"""Tests de configuración, autenticación, polling y retry."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from app.config import Config, ConfigError
from app.retry import Backoff, reintentar


class TestConfig(unittest.TestCase):
    def test_valores_por_defecto(self):
        cfg = Config({})
        self.assertEqual(cfg.printer_port, 9100)
        self.assertEqual(cfg.polling_interval, 3)
        self.assertEqual(cfg.printer_type, "ethernet")

    def test_carga_desde_archivo(self):
        with tempfile.TemporaryDirectory() as tmp:
            ruta = Path(tmp) / "config.json"
            ruta.write_text('{"server_url": "https://x.com", "device_id": "bridge-9", "token": "t"}')
            cfg = Config.cargar(ruta)
            self.assertEqual(cfg.device_id, "bridge-9")
            self.assertIn("Bearer", cfg.auth_header["Authorization"])

    def test_config_invalida_sin_server(self):
        cfg = Config({"token": "x"})
        with self.assertRaises(ConfigError):
            cfg.validar()

    def test_config_invalida_sin_token(self):
        cfg = Config({"server_url": "https://x.com"})
        with self.assertRaises(ConfigError):
            cfg.validar()

    def test_falta_archivo(self):
        with self.assertRaises(ConfigError):
            Config.cargar("/inexistente/config.json")


class TestAuth(unittest.TestCase):
    def test_header_bearer(self):
        cfg = Config({"server_url": "https://x.com", "token": "secreto"})
        self.assertEqual(cfg.auth_header["Authorization"], "Bearer secreto")


class TestRetry(unittest.TestCase):
    def test_backoff_creciente_y_limitado(self):
        from unittest.mock import patch

        b = Backoff(base=3, maximo=30)
        sleeps: list[float] = []
        with patch("app.retry.time.sleep", lambda s: sleeps.append(s)):
            for _ in range(8):
                b.esperar()
        # Serie esperada: 3,5,10,20,30 y clavado en 30 a partir de ahí.
        self.assertEqual(sleeps[:5], [3, 5.0, 10.0, 20.0, 30.0])
        self.assertEqual(sleeps[5:], [30.0, 30.0, 30.0])

    def test_backoff_reset_vuelve_a_la_base(self):
        from unittest.mock import patch

        b = Backoff(base=3, maximo=30)
        sleeps: list[float] = []
        with patch("app.retry.time.sleep", lambda s: sleeps.append(s)):
            b.esperar()
            b.esperar()          # 3, 5
            b.reset()
            b.esperar()          # vuelve a 3
        self.assertEqual(sleeps, [3, 5.0, 3])

    def test_reintentar_devuelve_resultado(self):
        contador = {"n": 0}

        def tarea():
            contador["n"] += 1
            if contador["n"] < 3:
                raise ValueError("aun no")
            return "ok"

        resultado = reintentar(tarea, max_intentos=5)
        self.assertEqual(resultado, "ok")

    def test_reintentar_relanza_al_agotar(self):
        contador = {"n": 0}
        errores = []

        def tarea():
            contador["n"] += 1
            raise ValueError("siempre")

        with self.assertRaises(ValueError):
            reintentar(tarea, max_intentos=2, on_error=lambda e: errores.append(e))
        self.assertEqual(len(errores), 2)


if __name__ == "__main__":
    unittest.main()