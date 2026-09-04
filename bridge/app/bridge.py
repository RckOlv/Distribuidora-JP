"""Circuito de polling y procesamiento de trabajos del bridge.

Flujo por trabajo:
  1. GET /pendientes  → claim atómico de un trabajo (PROCESANDO + datos).
  2. Genera bytes ESC/POS desde el snapshot del ticket.
  3. Envía a la impresora por TCP.
  4. Si imprimió → PUT /impreso.
  5. Si hay error de impresora → PUT /error (reintentar=true), vuelve a
     PENDIENTE en el servidor y se reintentará controladamente.

Ante fallos de red con Laravel aplica backoff progresivo sin perder trabajos.
"""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import Any

from app.api_client import ApiClient, ApiError
from app.config import Config
from app.print_job import ErrorPermanente, TicketSnapshot, generar_ticket
from app.printer import ImpresoraError, PrinterEthernet, generar_ticket_prueba
from app.retry import Backoff
from app.state import EstadoLocal


@dataclass
class EstadoRuntime:
    """Estado en memoria, expuesto a la interfaz simple."""
    estado_bridge: str = "DETENIDO"
    servidor_ok: bool = False
    impresora_ok: bool = False
    pendientes: int = 0
    ultimo_ticket: str | None = None
    ultimo_error: str | None = None
    imprimiendo: bool = False


class PrintBridge:
    def __init__(self, config: Config, logger: logging.Logger,
                 estado_local: EstadoLocal | None = None,
                 estado: EstadoRuntime | None = None) -> None:
        self.config = config
        self.logger = logger
        self.logger.info("Inicio del bridge (dispositivo=%s)", config.device_id)
        self.api = ApiClient(config)
        self.printer = PrinterEthernet(config)
        self.backoff = Backoff(config.polling_interval, config.polling_max_backoff)
        self.estado_local = estado_local or EstadoLocal(config.state_file)
        self.runtime = estado or EstadoRuntime()
        self._detener = False

    def detener(self) -> None:
        self._detener = True
        self.logger.info("Detención solicitada para el bridge.")

    # --- ciclo principal ---

    def ejecutar(self) -> None:
        self.runtime.estado_bridge = "EJECUTANDO"
        self.logger.info("Bridge en ejecución (polling cada %ss).",
                         self.config.polling_interval)

        while not self._detener:
            try:
                trabajo = self.api.obtener_trabajo()
                self.backoff.reset()
                self.runtime.servidor_ok = True

                if trabajo:
                    self.procesar_work(trabajo)
                else:
                    self.runtime.pendientes = 0
                    self.estado_local.actualizar(ultimo_poll_ok=True)
            except ApiError as e:
                self._fallo_red(f"Error de API: {e}")
            except Exception as e:  # noqa: BLE001
                self._fallo_red(f"Error inesperado: {e}")

            if not self._detener:
                self._esperar()

    def _esperar(self) -> None:
        if self.runtime.servidor_ok:
            # duración mínima entre polls = intervalo normal
            time.sleep(max(self.config.polling_interval, 1))
        else:
            self.backoff.esperar()

    def _fallo_red(self, mensaje: str) -> None:
        self.runtime.servidor_ok = False
        self.runtime.ultimo_error = mensaje
        self.logger.warning("Sin conexión con Laravel: %s. Backoff activo.", mensaje)

    def procesar_work(self, trabajo: dict[str, Any]) -> None:
        id_trabajo = int(trabajo["id"])
        numero = str(trabajo.get("numero_ticket", ""))
        contenido_texto = str(trabajo.get("contenido", ""))
        tipo = str(trabajo.get("tipo", "VENTA")).upper() or "VENTA"
        es_reimpresion = tipo == "REIMPRESION"
        self.runtime.pendientes = 1
        self.logger.info("Trabajo reclamado: N°%s (id=%s, tipo=%s).",
                         numero, id_trabajo, tipo)

        # 1) Interpreto el snapshot ANTES de tocar la impresora. Un contenido
        #    inválido/corrupto es un error permanente: no reintentarlo.
        try:
            snapshot = self._construir_snapshot(contenido_texto)
            datos = generar_ticket(snapshot, es_reimpresion=es_reimpresion)
        except ErrorPermanente as e:
            mensaje = f"Error permanente en N°{numero}: {e}"
            self.runtime.imprimiendo = False
            self.runtime.ultimo_error = mensaje
            self.logger.error(mensaje)
            self._informar_error(id_trabajo, mensaje, reintentar=False)
            return
        except Exception as e:  # noqa: BLE001
            mensaje = f"Error interno al generar N°{numero}: {e}"
            self.runtime.imprimiendo = False
            self.runtime.ultimo_error = mensaje
            self.logger.error(mensaje)
            self._informar_error(id_trabajo, mensaje, reintentar=False)
            return

        # 2) Impresión por red y confirmación. Fallos aquí son transitorios
        #    (impresora apagada, timeout, red caída) y pueden reintentarse.
        self.logger.info("Iniciando impresión de N°%s.", numero)
        self.runtime.imprimiendo = True
        try:
            self.printer.imprimir(datos)
            self.api.marcar_impreso(id_trabajo)
        except (ImpresoraError, ApiError) as e:
            mensaje = f"Fallo al imprimir N°{numero}: {e}"
            self._registrar_fallo_transitorio(id_trabajo, numero, mensaje)
            return

        self.runtime.imprimiendo = False
        self.runtime.impresora_ok = True
        self.runtime.ultimo_ticket = numero
        self.runtime.ultimo_error = None
        self.estado_local.actualizar(ultimo_ticket=numero, ultimo_error=None)
        self.logger.info("Trabajo N°%s impreso y confirmado.", numero)

    def _construir_snapshot(self, contenido_texto: str) -> TicketSnapshot:
        """Parsea el snapshot; un JSON inválido es un error permanente."""
        try:
            datos = json.loads(contenido_texto or "{}")
        except ValueError as e:
            raise ErrorPermanente(f"Contenido del snapshot no es JSON válido: {e}") from e
        return TicketSnapshot(datos)

    def _registrar_fallo_transitorio(
        self, id_trabajo: int, numero: str, mensaje: str,
    ) -> None:
        self.runtime.imprimiendo = False
        self.runtime.impresora_ok = False
        self.runtime.ultimo_error = mensaje
        self.logger.error("Fallo al imprimir N°%s: %s", numero, mensaje)
        self._informar_error(id_trabajo, mensaje, reintentar=True)

    def _informar_error(self, id_trabajo: int, mensaje: str, reintentar: bool) -> None:
        try:
            self.api.informar_error(id_trabajo, mensaje, reintentar=reintentar)
            if reintentar:
                self.logger.info("Trabajo %s devuelto a PENDIENTE para reintento.", id_trabajo)
            else:
                self.logger.info("Trabajo %s marcado como ERROR (no se reintentará).", id_trabajo)
        except ApiError as e:
            self.logger.warning("No se pudo informar el estado de %s: %s", id_trabajo, e)

    # --- prueba de impresión local ---

    def imprimir_prueba(self) -> bool:
        """Genera y envía un ticket de prueba SIN crear venta ni trabajo."""
        self.logger.info("Imprimiendo ticket de prueba.")
        datos = generar_ticket_prueba(self.config)
        try:
            self.printer.imprimir(datos)
            self.runtime.impresora_ok = True
            self.runtime.ultimo_error = None
            self.logger.info("Ticket de prueba impreso OK.")
            return True
        except ImpresoraError as e:
            self.runtime.impresora_ok = False
            self.runtime.ultimo_error = str(e)
            self.logger.error("Prueba de impresión falló: %s", e)
            return False