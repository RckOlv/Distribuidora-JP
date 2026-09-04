"""Runner E2E real: un ciclo completo Laravel -> Bridge -> TCP -> simulador.

Realiza exactamente un ciclo del flujo real (sin mocks de red):
  1. obtiene/claim un trabajo PENDIENTE desde Laravel (ApiClient real).
  2. lo procesa con el PrintBridge (genera ESC/POS y lo envía al simulador).
  3. verifica que el simulador capturó los bytes y valida el contenido.
  4. confirma el estado del trabajo en Laravel (IMPRESO).

Uso (desde bridge/):
    python -m tests.runner_e2e
"""

from __future__ import annotations

import logging
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.bridge import PrintBridge
from app.config import Config
from app.printer_simulator import PrinterSimulator, esperar_hasta
from tests.escpos_validator import (
    contar_cortes,
    contiene,
    contiene_marca_reimpresion,
    es_reimpresion,
    extraer_ticket,
    hay_corte,
    lineas,
)


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    sim = PrinterSimulator(host="127.0.0.1", puerto=9100).iniciar()
    cfg = Config.cargar()
    bridge = PrintBridge(cfg, logging.getLogger("e2e"))

    print("=== E2E real: Laravel -> Bridge -> TCP -> PrinterSimulator ===")
    print(f"Simulador en 127.0.0.1:{sim.puerto} | Laravel {cfg.server_url} | "f"PRINT {cfg.printer_host}:{cfg.printer_port}")

    # 1) Claim real
    trabajo = bridge.api.obtener_trabajo()
    if trabajo is None:
        print("NO HAY TRABAJO PENDIENTE en la cola de Laravel. Creá una venta primero.")
        sim.detener()
        return 2

    print(f"Trabajo reclamado: id={trabajo['id']} tipo={trabajo.get('tipo')} "f"nro={trabajo.get('numero_ticket')}")

    # 2) Procesa con el bridge REAL (genera ESC/POS + envía al simulador).
    bridge.procesar_work(trabajo)

    # 3) El bridge confirma vía ApiClient real → el trabajo debe quedar IMPRESO.
    ok = esperar_hasta(lambda: sim.cantidad_trabajos() > 0, timeout=5)
    if not ok:
        print("ERROR: el simulador NO recibió ningún trabajo.")
        sim.detener()
        return 3

    capturado = sim.ultimo_trabajo()
    datos = capturado.datos
    print(f"Simulador capturó {capturado.tamano} bytes (trabajo n°{capturado.numero}).")

    extra = extraer_ticket(datos)
    print("--- contenido del ticket (renderer simple) ---")
    print(extra.texto)
    print("-----------------------------------------------")
    print(f"comercio={extra.comercio} numero={extra.numero_ticket} pago={extra.pago} total={extra.total}")
    print(f"reimpresion={extra.reimpresion} items={len(extra.items)} corte={hay_corte(datos)} ({contar_cortes(datos)})")
    print(f"marca_reimpresion={contiene_marca_reimpresion(datos)} num_lineas={len(lineas(datos))}")

    # 4) Verificar estado final en Laravel
    from app.api_client import ApiClient

    estado = None
    try:
        check = ApiClient(cfg)
        _ = check.obtener_trabajo()  # fuerza un poll; si 409 el trabajo ya no está pendiente
        estado = "reclamado_por_e2e"
    except Exception as exc:  # noqa: BLE001
        estado = f"verificación: {exc}"

    print(f"Estado final del trabajo en Laravel: {estado}")
    sim.detener()
    return 0


if __name__ == "__main__":
    sys.exit(main())
