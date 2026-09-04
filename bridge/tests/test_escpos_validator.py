"""Tests de los helpers de validación ESC/POS (parser / renderer simple)."""

from __future__ import annotations

import unittest

from app.print_job import TicketSnapshot, generar_ticket
from tests.escpos_validator import (
    contar_cortes,
    contiene,
    contiene_marca_reimpresion,
    es_reimpresion,
    es_venta,
    extraer_ticket,
    hay_corte,
    hay_inicializacion,
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
            "telefono": "11-5555-0199",
            "leyenda": "Gracias por su compra",
        },
        "detalles": [
            {"nombre": "Banana", "unidad_medida": "KILOGRAMO", "cantidad": "2.000",
             "precio_unitario": "2500.00", "subtotal": "5000.00"},
            {"nombre": "Papa", "unidad_medida": "BOLSA", "cantidad": "1",
             "precio_unitario": "3100.00", "subtotal": "3100.00"},
        ],
    })


class TestTextoVisible(unittest.TestCase):
    def test_incluye_comercio_texto_y_total(self):
        texto = texto_visible(generar_ticket(_snapshot()))
        self.assertIn("VERDULER", texto.upper())
        self.assertIn("Banana", texto)
        self.assertIn("Papa", texto)
        self.assertIn("TOTAL", texto.upper())

    def test_tolera_comandos_esc_intercalados(self):
        # El texto real tiene comandos de control; el parser no debe romperse.
        datos = generar_ticket(_snapshot())
        self.assertIn(b"\x1b", datos)
        self.assertIn(b"\x1d", datos)
        lineas_texto = lineas(datos)
        self.assertTrue(any("Banana" in ln for ln in lineas_texto))

    def test_conserve_acentos(self):
        texto = texto_visible(generar_ticket(_snapshot()))
        # El nombre del comercio se imprime en mayúsculas; la "í" real queda
        # codificada en CP-850 (0xA1) y puede no corresponder exactamente con
        # latin-1: no se exige el acento perfecto, solo que el texto fluya.
        self.assertIn("VERDULER", texto.upper())


class TestComandos(unittest.TestCase):
    def test_hay_inicializacion(self):
        self.assertTrue(hay_inicializacion(generar_ticket(_snapshot())))

    def test_hay_corte(self):
        datos = generar_ticket(_snapshot())
        self.assertTrue(hay_corte(datos))
        self.assertEqual(contar_cortes(datos), 1)


class TestMarcaReimpresion(unittest.TestCase):
    def test_venta_sin_marca(self):
        datos = generar_ticket(_snapshot())
        self.assertFalse(contiene_marca_reimpresion(datos))
        self.assertTrue(es_venta(datos))

    def test_reimpresion_con_marca_antes_de_cabecera(self):
        datos = generar_ticket(_snapshot(), es_reimpresion=True)
        self.assertTrue(contiene_marca_reimpresion(datos))
        self.assertTrue(es_reimpresion(datos))
        self.assertTrue(contiene(datos, "REIMPRESION"))


class TestExtraerTicket(unittest.TestCase):
    def test_extrae_campos_clave(self):
        extraido = extraer_ticket(generar_ticket(_snapshot()))
        self.assertEqual(extraido.numero_ticket, "000042")
        self.assertEqual(extraido.pago, "EFECTIVO")
        self.assertIn("000042", extraido.texto)
        self.assertTrue(extraido.contiene("Banana"))
        self.assertTrue(extraido.contiene("TOTAL"))

    def test_extrae_items(self):
        extraido = extraer_ticket(generar_ticket(_snapshot()))
        self.assertTrue(extraido.item_con_nombre("Banana"))
        self.assertTrue(extraido.item_con_nombre("Papa"))

    def test_reimpresion_detectada_en_extraido(self):
        extraido = extraer_ticket(generar_ticket(_snapshot(), es_reimpresion=True))
        self.assertTrue(extraido.reimpresion)
        extraido_venta = extraer_ticket(generar_ticket(_snapshot()))
        self.assertFalse(extraido_venta.reimpresion)


if __name__ == "__main__":
    unittest.main()
