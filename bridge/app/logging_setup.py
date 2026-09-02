"""Logging estructurado con rotación.

Nunca se loguea el token ni credenciales. Solo eventos operativos.
"""

from __future__ import annotations

import logging
from logging.handlers import RotatingFileHandler
from pathlib import Path

FORMATO = "%(asctime)s | %(levelname)s | %(message)s"


def configurar_logging(directorio: Path) -> logging.Logger:
    logger = logging.getLogger("verduleria.bridge")
    logger.setLevel(logging.INFO)
    logger.propagate = False

    if logger.handlers:
        return logger

    directorio.mkdir(parents=True, exist_ok=True)

    fh = RotatingFileHandler(
        directorio / "bridge.log",
        maxBytes=1_000_000,
        backupCount=3,
        encoding="utf-8",
    )
    fh.setFormatter(logging.Formatter(FORMATO))
    logger.addHandler(fh)

    return logger


def agregar_consola(logger: logging.Logger) -> None:
    if not any(isinstance(h, logging.StreamHandler) for h in logger.handlers):
        sh = logging.StreamHandler()
        sh.setFormatter(logging.Formatter(FORMATO))
        logger.addHandler(sh)