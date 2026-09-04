"""Tests unitarios del simulador de impresora TCP ESC/POS."""

from __future__ import annotations

import socket
import threading
import time
import unittest

from app.printer_simulator import PrinterSimulator, TrabajoCapturado, esperar_hasta


def _enviar(puerto: int, datos: bytes, host: str = "127.0.0.1") -> None:
    """Abre una conexión, envía los bytes y la cierra (como el Print Bridge)."""
    with socket.create_connection((host, puerto), timeout=5) as sock:
        sock.sendall(datos)


class TestSimulador(unittest.TestCase):
    def test_inicia_y_escucha_en_tcp(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            self.assertIsNotNone(sim.puerto)
            # Conectarse demuestra que hay un socket escuchando.
            with socket.create_connection(("127.0.0.1", sim.puerto), timeout=5) as sock:
                self.assertTrue(sock.fileno() > 0)

    def test_acepta_conexion_y_recibe_bytes(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            _enviar(sim.puerto, b"MANDAMIENTO DE PRUEBA")
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            trabajo = sim.ultimo_trabajo()
            self.assertIsInstance(trabajo, TrabajoCapturado)
            self.assertEqual(trabajo.datos, b"MANDAMIENTO DE PRUEBA")
            self.assertGreater(trabajo.tamano, 0)
            self.assertGreater(trabajo.recibido_en, 0)

    def test_registra_varios_trabajos_en_orden(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            # El bridge procesa UN trabajo a la vez: cada conexión se completa y
            # se confirma su registro antes de la siguiente (orden determinista).
            esperados = [b"primer-trabajo", b"segundo-trabajo", b"tercer-trabajo"]
            for i, datos in enumerate(esperados, start=1):
                _enviar(sim.puerto, datos)
                self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == i))
            trabajos = sim.trabajos()
            self.assertEqual([t.numero for t in trabajos], [1, 2, 3])
            self.assertEqual([t.datos for t in trabajos], esperados)

    def test_registra_cliente_y_tamano(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            datos = b"0" * 500
            _enviar(sim.puerto, datos)
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            t = sim.ultimo_trabajo()
            self.assertEqual(t.tamano, 500)
            self.assertIn("127.0.0.1", t.cliente)

    def test_shutdown_limpio_no_deja_socket_escuchando(self):
        sim = PrinterSimulator(puerto=0).iniciar()
        puerto = sim.puerto
        sim.detener()
        # El puerto ya no debe aceptar conexiones.
        time.sleep(0.1)
        with self.assertRaises(OSError):
            with socket.create_connection(("127.0.0.1", puerto), timeout=1):
                pass

    def test_apagado_detecta_conexion_rechazada(self):
        sim = PrinterSimulator(puerto=0).iniciar()
        puerto = sim.puerto
        sim.detener()
        time.sleep(0.1)
        # Sin servidor escuchando -> el envío falla (ConnectionRefused).
        with self.assertRaises(OSError):
            _enviar(puerto, b"hola")


class TestModosDeFalla(unittest.TestCase):
    def test_leer_parcial_y_cerrar_registra_el_trozo_recibido(self):
        with PrinterSimulator(puerto=0, leer_parcial_y_cerrar=10).iniciar() as sim:
            # El envío puede fallar (reset) pero el simulador ya registra lo leído.
            try:
                _enviar(sim.puerto, b"1234567890ABCDEFGHIJ")
            except OSError:
                pass
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            t = sim.ultimo_trabajo()
            self.assertEqual(t.datos, b"1234567890")
            self.assertEqual(t.tamano, 10)

    def test_callback_no_lee_mantiene_conexion_abierta(self):
        def _no_leer(conexion: socket.socket) -> None:
            # mantiene la conexión abierta sin leer (simula timeout del emisor)
            time.sleep(1.0)
            conexion.close()  # type: ignore[attr-defined]

        with PrinterSimulator(puerto=0, recibir_callback=_no_leer).iniciar() as sim:
            with socket.create_connection(("127.0.0.1", sim.puerto), timeout=2) as sock:
                sock.settimeout(0.2)
                # El servidor nunca lee ni responde: no debe haber bytes.
                try:
                    sock.sendall(b"x" * 100)
                    datos = sock.recv(100)
                    self.assertEqual(datos, b"")
                except socket.timeout:
                    pass

    def test_delay_de_lectura_retrasa_la_recepcion(self):
        inicio = time.time()
        with PrinterSimulator(puerto=0, delay_lectura=0.3).iniciar() as sim:
            try:
                _enviar(sim.puerto, b"datos")
            except OSError:
                pass
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1, timeout=3))
            self.assertGreaterEqual(time.time() - inicio, 0.2)


if __name__ == "__main__":
    unittest.main()
