"""Conexión a la impresora térmica por Ethernet/TCP (ESC/POS).

Maneja timeouts y errores de conexión sin bloquear el servicio; no depende de
bibliotecas externas de impresión.
"""

from __future__ import annotations

import socket

from app.config import Config
from app.escpos import EscPos

# Puerto por defecto para pruebas; el real es configurable.
PUERTO_POR_DEFECTO = 9100


class ImpresoraError(Exception):
    """Error de comunicación con la impresora."""


class PrinterEthernet:
    """Conecta por TCP, envía bytes y cierra la conexión."""

    def __init__(self, config: Config) -> None:
        self._config = config

    def nombre(self) -> str:
        return f"TP95 {self._config.printer_host}:{self._config.printer_port}"

    def imprimir(self, datos: bytes) -> None:
        host = self._config.printer_host
        puerto = int(self._config.printer_port or PUERTO_POR_DEFECTO)
        timeout = int(self._config.printer_timeout)

        try:
            with socket.create_connection((host, puerto), timeout=timeout) as sock:
                sock.settimeout(timeout)
                sock.sendall(datos)
        except (socket.timeout, TimeoutError) as e:
            raise ImpresoraError(f"Timeout de conexión con {host}:{puerto}: {e}") from e
        except (ConnectionRefusedError, ConnectionError) as e:
            raise ImpresoraError(f"Conexión rechazada con {host}:{puerto} (¿impresora apagada?)") from e
        except OSError as e:
            raise ImpresoraError(f"Error de red hacia {host}:{puerto}: {e}") from e


def generar_ticket_prueba(config: Config) -> bytes:
    """Ticket de prueba generado LOCALMENTE; no toca Laravel ni la cola."""
    esc = EscPos()
    esc.texto("VERDULERIA", negrita=True, doble=True, alinear=EscPos.ALINEAR_CENTRO)
    esc.separador()
    esc.texto("PRUEBA DE IMPRESION", negrita=True, alinear=EscPos.ALINEAR_CENTRO)
    esc.separador()
    esc.fila_alineada("Impresora", "TP95 80mm")
    esc.fila_alineada("IP", config.printer_host)
    esc.fila_alineada("Puerto", str(config.printer_port or PUERTO_POR_DEFECTO))
    esc.fila_alineada("Conexion Ethernet", "OK")
    esc.fila_alineada("ESC/POS", "OK")
    esc.separador()
    # Caracteres españoles para auditar codificación
    esc.texto("Ñandú á é í ó ú ü")
    esc.texto("Á É Í Ó Ú Ñ ¿ ¡")
    esc.separador()
    import datetime

    ahora = datetime.datetime.now().strftime("%d/%m/%Y %H:%M")
    esc.fila_alineada("Fecha", ahora)
    esc.separador()
    esc.texto("PRUEBA OK", negrita=True, alinear=EscPos.ALINEAR_CENTRO)
    esc.alimentar(2)
    esc.cortar()
    return esc.bytes()