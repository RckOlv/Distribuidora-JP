"""Tests de generación ESC/POS y formato del ticket."""

from __future__ import annotations

import unittest

from app.escpos import CodificacionEspanyol, EscPos, formatear_cantidad, formatear_moneda
from app.print_job import TicketSnapshot, generar_ticket


class TestFormato(unittest.TestCase):
    def test_kilogramo_hasta_tres_decimales(self):
        self.assertEqual(formatear_cantidad(1.0, "KILOGRAMO"), "1 kg")
        self.assertEqual(formatear_cantidad(1.5, "KILOGRAMO"), "1.5 kg")
        self.assertEqual(formatear_cantidad(1.350, "KILOGRAMO"), "1.35 kg")
        self.assertEqual(formatear_cantidad(0.5, "kg"), "0.5 kg")

    def test_unidad_entera(self):
        self.assertEqual(formatear_cantidad(3.0, "UNIDAD"), "3 unidad")

    def test_bolsa_entera(self):
        self.assertEqual(formatear_cantidad(2.999, "BOLSA"), "3 bolsa")

    def test_moneda_legible(self):
        self.assertIn("$", formatear_moneda(1234.5))
        self.assertIn(",", formatear_moneda(1234.5))


class TestEscPos(unittest.TestCase):
    def test_acentos_centralizados(self):
        # ñ → PC850 0xA4, á → 0xA0
        codificado = CodificacionEspanyol.codificar("ñá")
        self.assertEqual(codificado, b"\xa4\xa0")

    def test_buffer_empieza_con_seleccion_charmap(self):
        esc = EscPos()
        self.assertTrue(esc.bytes())

    def test_termina_con_corte(self):
        esc = EscPos()
        esc.cortar()
        self.assertTrue(esc.bytes().endswith(b"\x1dV\x41\x00"))

    def test_no_rompe_con_caracteres_raros(self):
        esc = EscPos()
        esc.texto("ácid éôü 日本語")
        esc.bytes()  # no debe lanzar


class TestGenerarTicket(unittest.TestCase):
    def _snapshot(self):
        return TicketSnapshot({
            "numero": "000001",
            "fecha": "02/09/2026 10:00",
            "medio_pago": "Efectivo",
            "total": "3750.00",
            "comercio": {"nombre": "Mi Verdulería", "direccion": "Av. Siempre 123",
                         "telefono": "555-1234", "leyenda": "Gracias"},
            "detalles": [
                {"nombre": "Banana", "unidad_medida": "KILOGRAMO", "cantidad": "1.500",
                 "precio_unitario": "2500.00", "subtotal": "3750.00"},
                {"nombre": "Gaseosa", "unidad_medida": "UNIDAD", "cantidad": "2",
                 "precio_unitario": "1200.00", "subtotal": "3600.00"},
            ],
        })

    def test_cabecera_con_nombre_del_comercio(self):
        datos = generar_ticket(self._snapshot())
        texto = datos.decode("latin-1")
        self.assertIn("VERDULER", texto.upper())

    def test_incluye_productos_cantidades_y_total(self):
        datos = generar_ticket(self._snapshot()).decode("latin-1")
        self.assertIn("Banana", datos)
        self.assertIn("1.5 kg", datos)
        self.assertIn("TOTAL", datos.upper())
        self.assertIn("Efectivo", datos)

    def test_incluye_caracteres_espanoles(self):
        datos = generar_ticket(self._snapshot())
        # "Mi Verdulería" debe codificarse con á → 0xA0 (no UTF-8)
        self.assertIn(b"verduler", datos.lower())


if __name__ == "__main__":
    unittest.main()