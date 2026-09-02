"""Interfaz mínima del bridge (consola simple en vivo).

No es un segundo sistema de gestión: muestra estado, permite prueba de
impresión y listar logs. No interfiere con el servicio: este módulo solo se
usa con el comando `run --ui`.
"""

from __future__ import annotations

import threading
import time

from app.bridge import PrintBridge, EstadoRuntime
from app.logging_setup import agregar_consola


class InterfazSimple:
    def __init__(self, bridge: PrintBridge) -> None:
        self.bridge = bridge
        self._detener = False

    def iniciar(self) -> None:
        agregar_consola(self.bridge.logger)
        hilo = threading.Thread(target=self.bridge.ejecutar, daemon=True)
        hilo.start()
        self._loop_teclado()

    def _loop_teclado(self) -> None:
        while not self._detener:
            self._pintar()
            print("  [i] imprimir prueba   [l] ver log   [q] salir")
            try:
                opcion = input("  > ").strip().lower()
            except (EOFError, KeyboardInterrupt):
                break
            if opcion == "i":
                self.bridge.imprimir_prueba()
            elif opcion == "l":
                self._ver_log()
            elif opcion == "q":
                self._detener = True
                self.bridge.detener()
        self.bridge.detener()

    def _pintar(self) -> None:
        e = self.bridge.runtime
        print("\n=== Verduleria Print Bridge ===")
        print(f"Estado Bridge:   {e.estado_bridge}")
        print(f"Servidor:        {'OK' if e.servidor_ok else 'SIN CONEXION'}")
        print(f"Dispositivo:     {self.bridge.config.device_id}")
        print("Impresora:")
        print(f"  {self.bridge.printer.nombre()}")
        print(f"Estado impresora: {'CONECTADA' if e.impresora_ok else ('IMPRIMIENDO' if e.imprimiendo else 'DESCONOCIDO')}")
        print(f"Ultimo ticket:   {e.ultimo_ticket or '-'}")
        print(f"Ultimo error:    {e.ultimo_error or '-'}")

    def _ver_log(self) -> None:
        ruta = self.bridge.config.logs_dir / "bridge.log"
        if not ruta.exists():
            print("Sin logs aún.")
            return
        print("--- últimas líneas del log ---")
        with ruta.open("r", encoding="utf-8") as f:
            lineas = f.readlines()
        for linea in lineas[-25:]:
            print(linea.rstrip())