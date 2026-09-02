"""Tests de la conexión de impresora usando un socket TCP simulado.

No depende de una TP95 física.
"""

from __future__ import annotations

import socketserver
import threading
import unittest

from app.config import Config
from app.printer import ImpresoraError, PrinterEthernet, generar_ticket_prueba


class _EcoHandler(socketserver.StreamRequestHandler):
    """Servidor TCP que recibe y acumula los bytes enviados."""

    received: list = []

    def handle(self) -> None:
        datos = self.rfile.read()
        type(self).received.append(datos)


class TestPrinter(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.servidor = socketserver.TCPServer(("127.0.0.1", 0), _EcoHandler)
        cls.puerto = cls.servidor.server_address[1]
        cls.hilo = threading.Thread(target=cls.servidor.serve_forever, daemon=True)
        cls.hilo.start()

    @classmethod
    def tearDownClass(cls):
        cls.servidor.shutdown()
        cls.servidor.server_close()

    def _config(self):
        return Config({
            "printer": {"type": "ethernet", "host": "127.0.0.1",
                        "port": self.puerto, "timeout": 3},
        })

    def test_envia_bytes_con_exito(self):
        before = len(_EcoHandler.received)
        datos = b"\x1b@prueba"
        PrinterEthernet(self._config()).imprimir(datos)

        # El handler del servidor recibe en su propio hilo: esperar breve.
        for _ in range(50):
            if len(_EcoHandler.received) == before + 1:
                break
            import time
            time.sleep(0.02)

        self.assertEqual(len(_EcoHandler.received), before + 1)
        self.assertEqual(_EcoHandler.received[-1], datos)

    def test_conexion_rechazada(self):
        cfg = Config({"printer": {"type": "ethernet", "host": "127.0.0.1",
                                  "port": 1, "timeout": 2}})  # puerto cerrado
        with self.assertRaises(ImpresoraError):
            PrinterEthernet(cfg).imprimir(b"x")

    def test_prueba_genera_bytes_sin_impresora_real(self):
        cfg = Config({})
        datos = generar_ticket_prueba(cfg)
        self.assertGreater(len(datos), 20)
        self.assertTrue(datos.startswith(b"\x1b"))


if __name__ == "__main__":
    unittest.main()