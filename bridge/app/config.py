"""Configuración del print bridge.

Carga config.json con valores por defecto. El archivo real contiene el token,
por lo que no debe commitearse: se distribuye `config.example.json`.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any


class ConfigError(Exception):
    """Configuración inválida o inaccesible."""


_DEFAULTS: dict[str, Any] = {
    "server_url": "",
    "device_id": "bridge-001",
    "token": "",
    "polling_interval": 3,
    "polling_max_backoff": 30,
    "polling_reset_backoff": 3,
    "printer": {
        "type": "ethernet",
        "host": "192.168.1.50",
        "port": 9100,
        "timeout": 5,
    },
    "timeout_http": 10,
    "reintentos_max": 5,
    "logs_dir": "logs",
    "state_file": "state.json",
}


def _merge(objetivo: dict[str, Any], fuente: dict[str, Any]) -> dict[str, Any]:
    for clave, valor in fuente.items():
        if isinstance(valor, dict) and isinstance(objetivo.get(clave), dict):
            _merge(objetivo[clave], valor)
        else:
            objetivo[clave] = valor
    return objetivo


class Config:
    """Acceso tipado a la configuración."""

    def __init__(self, datos: dict[str, Any]) -> None:
        self._datos = _merge(json.loads(json.dumps(_DEFAULTS)), datos)

    @classmethod
    def cargar(cls, ruta: str | Path | None = None) -> "Config":
        ruta = Path(ruta or os.environ.get("BRIDGE_CONFIG", "config.json")).resolve()

        if not ruta.exists():
            raise ConfigError(f"No existe config.json en {ruta}. Copiá config.example.json.")

        try:
            with ruta.open("r", encoding="utf-8") as f:
                datos = json.load(f)
        except (OSError, json.JSONDecodeError) as e:
            raise ConfigError(f"No se pudo leer {ruta}: {e}") from e

        return cls(datos)

    def validar(self) -> None:
        if not self.server_url:
            raise ConfigError("Falta 'server_url' (https://dominio.com).")
        if not self.token:
            raise ConfigError("Falta 'token' del dispositivo en config.json.")
        if self.printer_type != "ethernet":
            raise ConfigError(f"Tipo de impresora no soportado: {self.printer_type}")
        if not self.printer_host:
            raise ConfigError("Falta 'printer.host'.")
        if not (1 <= self.printer_port <= 65535):
            raise ConfigError(f"Puerto de impresora inválido: {self.printer_port}")
        if self.polling_interval < 1 or self.polling_max_backoff < self.polling_interval:
            raise ConfigError("Intervalos de polling inválidos.")

    # --- server / auth ---
    @property
    def server_url(self) -> str:
        return str(self._datos["server_url"]).rstrip("/")

    @property
    def device_id(self) -> str:
        return str(self._datos["device_id"])

    @property
    def token(self) -> str:
        return str(self._datos["token"])

    @property
    def auth_header(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self.token}"}

    # --- http / timeouts ---
    @property
    def timeout_http(self) -> int:
        return int(self._datos["timeout_http"])

    # --- polling ---
    @property
    def polling_interval(self) -> float:
        return float(self._datos["polling_interval"])

    @property
    def polling_max_backoff(self) -> float:
        return float(self._datos["polling_max_backoff"])

    @property
    def polling_reset_backoff(self) -> float:
        return float(self._datos["polling_reset_backoff"])

    # --- printer ---
    @property
    def printer_type(self) -> str:
        return str(self._datos["printer"]["type"])

    @property
    def printer_host(self) -> str:
        return str(self._datos["printer"]["host"])

    @property
    def printer_port(self) -> int:
        return int(self._datos["printer"]["port"])

    @property
    def printer_timeout(self) -> int:
        return int(self._datos["printer"]["timeout"])

    # --- reintentos / archivos ---
    @property
    def reintentos_max(self) -> int:
        return int(self._datos["reintentos_max"])

    @property
    def logs_dir(self) -> Path:
        return Path(str(self._datos.get("logs_dir", "logs"))).resolve()

    @property
    def state_file(self) -> Path:
        return Path(str(self._datos.get("state_file", "state.json"))).resolve()