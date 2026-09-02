"""Ejecución del bridge como proceso de fondo o servicio de Windows.

Uso:
  python -m app.service.entry run        → proceso de fondo (pruebas Linux/Windows sin pywin32)
  python -m app.service.entry service    → servicio de Windows (necesita pywin32)
  python -m app.service.entry install    → instala el servicio
  python -m app.service.entry remove     → desinstala el servicio

El daemon responde a SIGTERM/SIGINT haciendo stop ordenado.
"""

from __future__ import annotations

import signal
import sys

from app.bridge import PrintBridge
from app.config import Config
from app.logging_setup import configurar_logging


def _crear() -> tuple[Config, PrintBridge]:
    config = Config.cargar()
    config.validar()
    logger = configurar_logging(config.logs_dir)
    return config, PrintBridge(config, logger)


def ejecutar_daemon() -> None:
    _config, bridge = _crear()
    puente_ref = {"bridge": bridge}

    def _stop(_s, _f) -> None:
        bridge.detener()

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            signal.signal(sig, _stop)
        except (ValueError, OSError):
            pass

    bridge.ejecutar()
    sys.exit(0)


def comando_instalar() -> None:
    try:
        import win32serviceutil  # noqa: F401
    except ImportError:
        print("pywin32 no está instalado. Instalalo con: pip install pywin32")
        sys.exit(1)

    from app.service.win_service import instalar_servicio

    instalar_servicio()


def comando_remover() -> None:
    from app.service.win_service import remover_servicio

    remover_servicio()


def main() -> None:
    comando = sys.argv[1] if len(sys.argv) > 1 else "run"

    if comando == "run":
        ejecutar_daemon()
    elif comando == "service":
        from app.service.win_service import ejecutar_servicio_windows

        ejecutar_servicio_windows()
    elif comando == "install":
        comando_instalar()
    elif comando == "remove":
        comando_remover()
    else:
        print("Comando desconocido. Uso: run | service | install | remove")
        sys.exit(2)


if __name__ == "__main__":
    main()