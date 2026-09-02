"""Estado local liviano del bridge (archivo JSON).

Sirve para diagnóstico y para reconocer un trabajo ya impreso recientemente,
sin depender de un motor de base de datos local. No guarda datos sensibles.
"""

from __future__ import annotations

import json
import threading
from pathlib import Path
from typing import Any


class EstadoLocal:
    def __init__(self, archivo: Path) -> None:
        self._archivo = archivo
        self._lock = threading.Lock()
        self._datos: dict[str, Any] = self._cargar()

    def _cargar(self) -> dict[str, Any]:
        try:
            with self._archivo.open("r", encoding="utf-8") as f:
                return json.load(f)
        except (OSError, json.JSONDecodeError):
            return {}

    def _guardar(self) -> None:
        with self._lock:
            try:
                self._archivo.parent.mkdir(parents=True, exist_ok=True)
                with self._archivo.open("w", encoding="utf-8") as f:
                    json.dump(self._datos, f, ensure_ascii=False, indent=2)
            except OSError:
                # El estado es secundario: ante fallo de escritura, continuar.
                pass

    def actualizar(self, **kargs: Any) -> None:
        with self._lock:
            self._datos.update(kargs)
        self._guardar()

    def get(self, clave: str, por_defecto: Any = None) -> Any:
        with self._lock:
            return self._datos.get(clave, por_defecto)