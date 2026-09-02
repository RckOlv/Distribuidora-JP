"""Autenticación de dispositivo: arma el header Bearer sin exponer el token."""

from __future__ import annotations

from app.config import Config


def auth_headers(config: Config) -> dict[str, str]:
    """Headers HTTP con el token del dispositivo (nunca se loguea)."""
    return {
        "Authorization": f"Bearer {config.token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }