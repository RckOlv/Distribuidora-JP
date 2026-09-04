"""Pruebas E2E del circuito real Bridge -> TCP -> PrinterSimulator.

Se ejercita el código REAL del bridge (``print_job.py`` + ``printer.py`` +
``socket TCP``) contra la impresora simulada. Solo el cliente HTTP hacia
Laravel (``ApiClient.obtener_trabajo/marcar_impreso/informar_error``) se
mockea, porque no hay un servidor Laravel en vuelo en esta suite.

Circuito validado en cada test:
    print_job.py -> printer.py -> socket TCP -> PrinterSimulator -> captura
"""

from __future__ import annotations

import logging
import socket
import tempfile
import unittest
from unittest.mock import Mock

from app.bridge import PrintBridge
from app.config import Config
from app.printer import ImpresoraError, PrinterEthernet
from app.print_job import generar_ticket, TicketSnapshot
from app.printer_simulator import PrinterSimulator, esperar_hasta
from tests.escpos_validator import (
    contar_cortes,
    contiene,
    contiene_marca_reimpresion,
    es_venta,
    extraer_ticket,
    hay_corte,
    lineas,
    texto_visible,
)


def _snapshot() -> TicketSnapshot:
    return TicketSnapshot({
        "numero": "000042",
        "fecha": "03/09/2026 10:30",
        "medio_pago": "EFECTIVO",
        "total": "8100.00",
        "comercio": {
            "nombre": "Mi Verdulería",
            "direccion": "Av. Siempre Viva 123",
            "leyenda": "Gracias por su compra",
        },
        "detalles": [
            {"nombre": "Banana", "unidad_medida": "KILOGRAMO", "cantidad": "2.000",
             "precio_unitario": "2500.00", "subtotal": "5000.00"},
            {"nombre": "Papa", "unidad_medida": "BOLSA", "cantidad": "1",
             "precio_unitario": "3100.00", "subtotal": "3100.00"},
        ],
    })


def _trabajo(numero: str, contenido: dict, tipo: str = "VENTA") -> dict:
    import json

    return {
        "id": int(numero),
        "numero_ticket": numero,
        "contenido": json.dumps(contenido),
        "tipo": tipo,
    }


def _puerto_libre() -> int:
    """Devuelve un puerto libre reservado y cerrado (para simular impresora apagada)."""
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.bind(("127.0.0.1", 0))
    puerto = s.getsockname()[1]
    s.close()
    return puerto


def _bridge_hacia(puerto: int) -> PrintBridge:
    cfg = Config({
        "server_url": "https://x.com",
        "token": "t",
        "polling_interval": 1,
        "printer": {"type": "ethernet", "host": "127.0.0.1", "port": puerto, "timeout": 2},
    })
    with tempfile.TemporaryDirectory() as tmp:
        cfg._datos["state_file"] = f"{tmp}/state.json"
        logger = logging.getLogger("e2e_" + str(puerto))
        return PrintBridge(cfg, logger)


class TestE2EVenta(unittest.TestCase):
    def test_venta_normal_se_imprime_y_confirma(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            bridge.api.informar_error = Mock()

            bridge.procesar_work(_trabajo("000042", _snapshot().contenido, "VENTA"))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            t = sim.ultimo_trabajo()
            self.assertGreater(t.tamano, 0)
            bridge.api.marcar_impreso.assert_called_once_with(42)

            extraido = extraer_ticket(t.datos)
            self.assertTrue(es_venta(t.datos))
            self.assertFalse(contiene_marca_reimpresion(t.datos))
            self.assertEqual(extraido.numero_ticket, "000042")
            self.assertEqual(extraido.pago, "EFECTIVO")
            self.assertTrue(extraido.contiene("Banana"))
            self.assertTrue(extraido.contiene("Papa"))
            self.assertTrue(extraido.contiene("TOTAL"))
            self.assertTrue(hay_corte(t.datos))

    def test_venta_confirma_datos_comerciales(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            bridge.procesar_work(_trabajo("000042", _snapshot().contenido, "VENTA"))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            texto = texto_visible(sim.ultimo_trabajo().datos)
            self.assertIn("Banana", texto)
            self.assertIn("Papa", texto)
            self.assertIn("$ 5.000,00", texto)
            self.assertIn("$ 8.100,00", texto)
            self.assertIn("EFECTIVO", texto)


class TestE2EReimpresion(unittest.TestCase):
    def test_reimpresion_lleva_marca_y_datos_historicos(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            bridge.procesar_work(_trabajo("000042", _snapshot().contenido, "REIMPRESION"))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            t = sim.ultimo_trabajo()
            self.assertTrue(contiene_marca_reimpresion(t.datos))

            extraido = extraer_ticket(t.datos)
            self.assertTrue(extraido.reimpresion)
            self.assertTrue(extraido.contiene("Banana"))
            self.assertTrue(extraido.contiene("$ 8.100,00"))
            # La marca aparece ANTES de la cabecera.
            superiores = texto_visible(t.datos).upper()
            self.assertLess(
                superiores.index("REIMPRESION"),
                superiores.index("VERDULER"),
            )

    def test_venta_y_reimpresion_no_se_confunden(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            # Trabajo 1: venta (sin marca). Trabajo 2: reimpresión (con marca).
            bridge.procesar_work(_trabajo("000042", _snapshot().contenido, "VENTA"))
            bridge.procesar_work(_trabajo("000043", _snapshot().contenido, "REIMPRESION"))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 2))
            trabajos = sim.trabajos()
            self.assertFalse(contiene_marca_reimpresion(trabajos[0].datos))
            self.assertTrue(contiene_marca_reimpresion(trabajos[1].datos))


class TestE2EHistorial(unittest.TestCase):
    def test_reimpresion_conserva_datos_historicos(self):
        """Cambia precio/nombre del producto ANTES de procesar; el simulador
        recibe los datos históricos del snapshot, no los actuales."""
        historico = TicketSnapshot({
            "numero": "000099",
            "fecha": "01/01/2026 09:00",
            "medio_pago": "DEBITO",
            "total": "6000.00",
            "comercio": {"nombre": "Verduleria", "leyenda": "G"},
            "detalles": [
                {"nombre": "Papa Vieja", "unidad_medida": "KILOGRAMO", "cantidad": "3.000",
                 "precio_unitario": "2000.00", "subtotal": "6000.00"},
            ],
        })

        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            bridge.procesar_work(_trabajo("000099", historico.contenido, "REIMPRESION"))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            texto = texto_visible(sim.ultimo_trabajo().datos)
            # Datos históricos.
            self.assertIn("Papa Vieja", texto)
            self.assertIn("3 kg", texto)
            self.assertIn("$ 6.000,00", texto)
            self.assertIn("01/01/2026", texto)
            # Los "actuales" (ficticios) no aparecen porque el snapshot es fijo.
            self.assertNotIn("Actualmente", texto)
            self.assertTrue(contiene_marca_reimpresion(sim.ultimo_trabajo().datos))


class TestE2EErroresRed(unittest.TestCase):
    def test_impresora_apagada_es_transitorio_y_no_impreso(self):
        puerto = _puerto_libre()  # sin servidor escuchando
        bridge = _bridge_hacia(puerto)
        bridge.api.marcar_impreso = Mock()
        bridge.api.informar_error = Mock()

        bridge.procesar_work(_trabajo("000001", _snapshot().contenido, "VENTA"))

        bridge.api.marcar_impreso.assert_not_called()
        bridge.api.informar_error.assert_called_once_with(
            1, unittest.mock.ANY, reintentar=True
        )
        self.assertFalse(bridge.runtime.impresora_ok)

    def test_impresora_apagada_no_pierde_el_trabajo(self):
        # El trabajo sigue disponible para reintentarlo: al levantar el simulador
        # el mismo trabajo se procesa con éxito.
        puerto = _puerto_libre()
        bridge = _bridge_hacia(puerto)
        bridge.api.marcar_impreso = Mock()
        bridge.api.informar_error = Mock()

        bridge.procesar_work(_trabajo("000001", _snapshot().contenido, "VENTA"))
        bridge.api.informar_error.assert_called_once()

        # "Encendemos" la impresora en el mismo puerto simulado.
        with PrinterSimulator(puerto=puerto).iniciar() as sim:
            bridge.api.marcar_impreso.reset_mock()
            bridge.api.informar_error.reset_mock()
            bridge.procesar_work(_trabajo("000001", _snapshot().contenido, "VENTA"))
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            bridge.api.marcar_impreso.assert_called_once_with(1)

    def test_desconexion_durante_impresion_causa_reset_del_emisor(self):
        # El servidor lee una parte (50 bytes) y cierra abruptamente mientras el
        # emisor todavía enviaba. Con un payload mayor a los buffers del socket,
        # el restante dispara RST en el emisor -> ImpresoraError (la traducción
        # que PrinterEthernet hace de una desconexión en mitad de la impresión).
        with PrinterSimulator(puerto=0, leer_parcial_y_cerrar=50).iniciar() as sim:
            cfg = Config({
                "printer": {"type": "ethernet", "host": "127.0.0.1",
                            "port": sim.puerto, "timeout": 2},
            })
            payload = b"B" * (64 * 1024 * 1024)
            with self.assertRaises(ImpresoraError):
                PrinterEthernet(cfg).imprimir(payload)
            # El simulador registró el trozo recibido antes de cerrar.
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            self.assertEqual(sim.ultimo_trabajo().datos, b"B" * 50)

    def test_desconexion_tratada_como_transitorio_en_el_bridge(self):
        # A nivel bridge: la desconexión (ImpresoraError) se informa como
        # transitorio, NO se marca IMPRESO y el trabajo queda recuperable.
        puerto = _puerto_libre()
        bridge = _bridge_hacia(puerto)
        bridge.api.marcar_impreso = Mock()
        bridge.api.informar_error = Mock()

        from app.printer import ImpresoraError

        bridge.printer.imprimir = Mock(side_effect=ImpresoraError("desconexion"))
        bridge.procesar_work(_trabajo("000001", _snapshot().contenido, "VENTA"))

        bridge.api.marcar_impreso.assert_not_called()
        bridge.api.informar_error.assert_called_once_with(
            1, unittest.mock.ANY, reintentar=True
        )

    def test_timeout_del_emisor_es_impresora_error_transitorio(self):
        # El servidor acepta pero nunca lee -> el sendall del emisor (payload
        # grande) llena el buffer del socket y provoca timeout en PrinterEthernet.
        def _no_leer(conexion: socket.socket) -> None:
            import time

            time.sleep(3)  # mantiene la conexión abierta sin leer
            try:
                conexion.close()
            except OSError:
                pass

        with PrinterSimulator(puerto=0, recibir_callback=_no_leer).iniciar() as sim:
            cfg = Config({
                "printer": {"type": "ethernet", "host": "127.0.0.1",
                            "port": sim.puerto, "timeout": 1},
            })
            # Payload mayor a los buffers del socket: fuerza bloqueo de sendall.
            payload_grande = b"A" * (64 * 1024 * 1024)
            with self.assertRaises(ImpresoraError):
                PrinterEthernet(cfg).imprimir(payload_grande)

    def test_timeout_tratado_como_transitorio_en_el_bridge(self):
        # A nivel bridge: un ImpresoraError por timeout se informa como
        # transitorio y NO se marca IMPRESO.
        puerto = _puerto_libre()
        bridge = _bridge_hacia(puerto)
        bridge.api.marcar_impreso = Mock()
        bridge.api.informar_error = Mock()

        from app.printer import ImpresoraError

        bridge.printer.imprimir = Mock(side_effect=ImpresoraError("timeout TCP"))
        bridge.procesar_work(_trabajo("000001", _snapshot().contenido, "VENTA"))

        bridge.api.marcar_impreso.assert_not_called()
        bridge.api.informar_error.assert_called_once_with(
            1, unittest.mock.ANY, reintentar=True
        )


class TestE2EMultiples(unittest.TestCase):
    def test_recibe_varias_ventas_y_reimpresiones_en_orden(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()

            for numero in ("000001", "000002", "000003", "000004"):
                tipo = "VENTA" if numero in ("000001", "000004") else "REIMPRESION"
                sn = _snapshot()
                sn.contenido["numero"] = numero
                bridge.procesar_work(_trabajo(numero, sn.contenido, tipo))

            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 4))
            trabajos = sim.trabajos()
            self.assertEqual([t.numero for t in trabajos], [1, 2, 3, 4])

            marcas = [contiene_marca_reimpresion(t.datos) for t in trabajos]
            # VENTA, REIMPRESION, REIMPRESION, VENTA
            self.assertEqual(marcas, [False, True, True, False])
            # Cada trabajo conserva sus propios bytes (número de ticket distinto).
            for t, esperado in zip(trabajos, ["000001", "000002", "000003", "000004"]):
                self.assertTrue(contiene(t.datos, esperado))
                self.assertGreater(t.tamano, 0)


class TestE2ECorte(unittest.TestCase):
    def test_cada_ticket_tiene_exactamente_un_corte(self):
        with PrinterSimulator(puerto=0).iniciar() as sim:
            bridge = _bridge_hacia(sim.puerto)
            bridge.api.marcar_impreso = Mock()
            bridge.procesar_work(_trabajo("000042", _snapshot().contenido, "VENTA"))
            self.assertTrue(esperar_hasta(lambda: sim.cantidad_trabajos() == 1))
            self.assertEqual(contar_cortes(sim.ultimo_trabajo().datos), 1)


if __name__ == "__main__":
    unittest.main()
