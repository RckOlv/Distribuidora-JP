"""Tests del circuito de polling/claim/impresion con mocks (sin Laravel real)."""

from __future__ import annotations

import logging
import tempfile
import unittest
from unittest.mock import Mock, patch

from app.bridge import EstadoRuntime, PrintBridge
from app.config import Config
from app.printer import ImpresoraError


def _config() -> Config:
    return Config({
        "server_url": "https://x.com",
        "token": "t",
        "polling_interval": 1,
        "polling_max_backoff": 2,
        "logs_dir": "/tmp",
    })


def _bridge() -> PrintBridge:
    with tempfile.TemporaryDirectory() as tmp:
        cfg = _config()
        cfg._datos["state_file"] = f"{tmp}/state.json"
        logger = logging.getLogger("test")
        return PrintBridge(cfg, logger)


class TestProcesarTrabajo(unittest.TestCase):
    def test_sin_trabajo_no_imprime(self):
        bridge = _bridge()
        bridge.api.obtener_trabajo = Mock(return_value=None)
        with patch.object(bridge, "procesar_work") as mock_procesar:
            # un solo ciclo: no debe llamar a procesar_work
            bridge.api.obtener_trabajo = Mock(return_value=None)
            bridge.procesar_work = Mock()
            bridge._detener = True
            bridge.ejecutar()
            self.assertEqual(bridge.procesar_work.call_count, 0)

    def test_impresion_exitosa_llama_marcar_impreso(self):
        bridge = _bridge()
        work = {
            "id": 1, "numero_ticket": "000001",
            "contenido": ('{"numero":"000001","fecha":"d","medio_pago":"E","total":"100",'
                          '"comercio":{"nombre":"X","leyenda":"G"},"detalles":[]}'),
        }
        bridge.api.marcar_impreso = Mock()
        with patch.object(bridge.printer, "imprimir") as mock_imprimir:
            bridge.procesar_work(work)
            mock_imprimir.assert_called_once()
            bridge.api.marcar_impreso.assert_called_once_with(1)

    def test_error_de_impresora_es_transitorio_y_vuelve_a_pendiente(self):
        from app.printer import ImpresoraError

        bridge = _bridge()
        bridge.api.informar_error = Mock()
        with patch.object(bridge.printer, "imprimir", side_effect=ImpresoraError("apagada")):
            bridge.procesar_work({"id": 1, "numero_ticket": "000001", "contenido": "{}"})
        # Error transitorio: se informa para devolver a PENDIENTE (reintentar=True).
        bridge.api.informar_error.assert_called_once_with(1, unittest.mock.ANY, reintentar=True)

    def test_snapshot_corrupto_es_error_permanente_y_no_reintenta(self):
        bridge = _bridge()
        bridge.api.informar_error = Mock()
        # Contenido no es JSON válido → error permanente (reintentar=False).
        bridge.procesar_work({"id": 7, "numero_ticket": "000007", "contenido": "no-soy-json"})
        bridge.api.informar_error.assert_called_once_with(7, unittest.mock.ANY, reintentar=False)

    def test_snapshot_invalido_es_error_permanente(self):
        bridge = _bridge()
        bridge.api.informar_error = Mock()
        # Estructura inválida ('detalles' no es lista) → permanente.
        trabajo = {
            "id": 8, "numero_ticket": "000008",
            "contenido": '{"numero":"8","detalles":"malo","total":"100","comercio":{}}',
        }
        bridge.procesar_work(trabajo)
        bridge.api.informar_error.assert_called_once_with(8, unittest.mock.ANY, reintentar=False)

    def test_error_permanente_no_entra_en_loop_de_reintentos(self):
        bridge = _bridge()
        bridge.api.informar_error = Mock()
        # Llamar dos veces al mismo trabajo corrupto no debe reintentarlo jamás.
        for _ in range(2):
            bridge.procesar_work({"id": 9, "numero_ticket": "000009", "contenido": "x"})
        # informar_error con reintentar=False (marcado ERROR en servidor).
        llamadas = [c for c in bridge.api.informar_error.call_args_list]
        self.assertTrue(all(kwargs["reintentar"] is False for _, kwargs in llamadas))

    def test_error_transitorio_puede_reintentarse_posteriormente(self):
        from app.printer import ImpresoraError

        bridge = _bridge()
        bridge.api.informar_error = Mock()
        with patch.object(bridge.printer, "imprimir",
                          side_effect=ImpresoraError("apagada")):
            bridge.procesar_work({"id": 1, "numero_ticket": "000001", "contenido": "{}"})
        bridge.api.informar_error.assert_called_once()
        # reintentar=True: el servidor lo devuelve a PENDIENTE, disponible para
        # un nuevo proceso más adelante (no hay bloqueo ni agotamiento).
        self.assertTrue(bridge.api.informar_error.call_args.kwargs["reintentar"])

    def test_no_se_registra_token_en_logs(self):
        import io

        buffer = io.StringIO()
        log = logging.getLogger("test_token")
        log.setLevel(logging.DEBUG)
        handler = logging.StreamHandler(buffer)
        log.addHandler(handler)

        with tempfile.TemporaryDirectory() as tmp:
            cfg = Config({
                "server_url": "https://x.com",
                "token": "ULTRA_SECRETO_TOKEN_123",
                "state_file": f"{tmp}/state.json",
            })
            bridge = PrintBridge(cfg, log)
            with patch.object(bridge.printer, "imprimir",
                              side_effect=ImpresoraError("cortada")):
                bridge.procesar_work({"id": 1, "numero_ticket": "1", "contenido": "{}"})

        salida = buffer.getvalue()
        self.assertNotIn("ULTRA_SECRETO_TOKEN_123", salida)
        log.removeHandler(handler)

    def test_prueba_no_crea_venta(self):
        bridge = _bridge()
        from app.printer import ImpresoraError
        with patch.object(bridge.printer, "imprimir") as mock:
            self.assertTrue(bridge.imprimir_prueba())
            mock.assert_called_once()
        # Ninguna llamada a la API
        bridge.api.marcar_impreso = Mock()
        bridge.api.informar_error = Mock()
        self.assertEqual(bridge.api.informar_error.call_count, 0)


class TestEstado(unittest.TestCase):
    def test_backoff_en_caida_de_red(self):
        bridge = _bridge()
        from app.api_client import ApiError
        bridge.api.obtener_trabajo = Mock(side_effect=ApiError("sin internet"))
        bridge._fallo_red("sin internet")
        self.assertFalse(bridge.runtime.servidor_ok)
        self.assertEqual(bridge.runtime.ultimo_error, "sin internet")


if __name__ == "__main__":
    unittest.main()