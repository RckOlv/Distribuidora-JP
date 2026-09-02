"""Punto de entrada del print bridge.

Uso:
  python -m bridge            (o desde bridge/:  python run_bridge.py)
                                  corre el bridge en primer plano (daemon)
  python -m bridge --ui       corre con la interfaz mínima interactiva
  python -m bridge --prueba   genera un ticket de prueba directo a la impresora
"""

from __future__ import annotations

import sys

from app.config import Config


def main() -> None:
    config = Config.cargar()
    config.validar()

    comando = sys.argv[1] if len(sys.argv) > 1 else ""

    if comando == "--ui":
        from app.bridge import PrintBridge
        from app.logging_setup import configurar_logging
        from app.ui import InterfazSimple

        logger = configurar_logging(config.logs_dir)
        interfaz = InterfazSimple(PrintBridge(config, logger))
        interfaz.iniciar()
        return

    if comando == "--prueba":
        from app.printer import ImpresoraError, PrinterEthernet, generar_ticket_prueba

        printer = PrinterEthernet(config)
        try:
            printer.imprimir(generar_ticket_prueba(config))
            print("Ticket de prueba enviado a la impresora OK.")
            return
        except ImpresoraError as e:
            print(f"Fallo de prueba: {e}")
            sys.exit(1)

    # Daemon por defecto (modo servicio portable)
    from app.service.entry import ejecutar_daemon

    ejecutar_daemon()


if __name__ == "__main__":
    main()