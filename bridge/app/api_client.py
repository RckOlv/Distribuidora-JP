"""Cliente HTTP hacia la API de impresión de Laravel.

Solo se comunican endpoints de la cola de impresión: obtener un trabajo
(claim), marcar impreso e informar error. No existe otra ruta usada por el
bridge, lo que acota su superficie a impresión únicamente.
"""

from __future__ import annotations

import json
from typing import Any

import requests

from app.auth import auth_headers
from app.config import Config

API_PENDIENTES = "/api/bridge/impresiones/pendientes"


class ApiError(Exception):
    """Error de comunicación o respuesta del servidor."""


class ApiClient:
    def __init__(self, config: Config) -> None:
        self._config = config
        self._session = requests.Session()

    # --- trabajo ---

    def obtener_trabajo(self) -> dict[str, Any] | None:
        """Reclama (claim) a lo sumo un trabajo; devuelve su payload o None."""
        respuesta = self._session.get(
            self._config.server_url + API_PENDIENTES,
            headers=auth_headers(self._config),
            timeout=self._config.timeout_http,
        )

        if respuesta.status_code == 401:
            raise ApiError("Autenticación de dispositivo rechazada (401).")
        if respuesta.status_code == 409:
            return None
        if not respuesta.ok:
            raise ApiError(f"HTTP {respuesta.status_code}: {respuesta.text[:200]}")

        datos = respuesta.json()
        return datos.get("trabajo")

    # --- confirmaciones ---

    def marcar_impreso(self, trabajo_id: int) -> None:
        self._enviar(f"/api/bridge/impresiones/{trabajo_id}/impreso", metodo="PUT")

    def _enviar(self, ruta: str, metodo: str = "PUT", payload: dict[str, Any] | None = None) -> Any:
        respuesta = self._session.request(
            metodo,
            self._config.server_url + ruta,
            headers=auth_headers(self._config),
            json=payload or {},
            timeout=self._config.timeout_http,
        )

        if respuesta.status_code in (401, 403, 409, 422, 404):
            raise ApiError(f"Respuesta inesperada {respuesta.status_code}: {respuesta.text[:200]}")
        if not respuesta.ok:
            raise ApiError(f"HTTP {respuesta.status_code}: {respuesta.text[:200]}")

        if not respuesta.content:
            return None
        return respuesta.json()

    def marcar_impreso_requiere_procesando(self):
        """Compatibilidad: marcar impreso ya incluye la validación de estado."""

    def informar_error(self, trabajo_id: int, mensaje: str, reintentar: bool = True) -> None:
        self._enviar(
            f"/api/bridge/impresiones/{trabajo_id}/error",
            payload={"error": mensaje, "reintentar": reintentar},
        )