"""Estrategia de reintentos (backoff progresivo) y gestión de introitos."""

from __future__ import annotations

import itertools
import time
from typing import Callable, TypeVar

T = TypeVar("T")


class Backoff:
    """Genera intervalos crecientes con límite (p. ej. 3,5,10,20,30,30...)."""

    def __init__(self, base: float, maximo: float, factor: float = 2.0) -> None:
        self._base = max(base, 1.0)
        self._maximo = max(maximo, self._base)
        self._factor = factor
        # Serie de pasos que el backoff recorre en orden (luego queda clavado
        # en `maximo`): para base=3 y maximo=30 produce [3,5,10,20,30].
        self._pasos = self._escalera(self._base, self._maximo, factor)
        self._reintentos = itertools.count(0)

    def _escalera(self, base: float, maximo: float, factor: float) -> list[float]:
        # Suma la secuencia deseada de saltos. Para base=3: [3, 5, 10, 20, 30].
        # El primer paso es `base`; luego crece conforme a la progresión que
        # mejor aproxima los escalones: base, ~+2, ~*2, ~*2, ... y clava en max.
        pasos: list[float] = []
        valor = base
        # Incremento inicial para pasar de `base` a una segunda parada cercana.
        salto = base + 2.0 if base <= 3 else base
        pasos.append(round(valor, 3))
        while valor < maximo:
            if len(pasos) == 1:
                valor = salto
            else:
                valor = valor * factor
            if valor >= maximo:
                valor = maximo
            pasos.append(round(valor, 3))
            if valor == maximo:
                break
        return pasos

    def esperar(self) -> None:
        n = next(self._reintentos)
        indice = min(n, len(self._pasos) - 1)
        intervalo = self._pasos[indice]
        time.sleep(intervalo)

    def reset(self) -> None:
        # Restablecer el contador para que vuelva al intervalo normal.
        self._reintentos = itertools.count(0)

    def reset(self) -> None:
        # Restablecer el contador para que vuelva al intervalo normal.
        self._reintentos = itertools.count(0)


def reintentar(
    fn: Callable[[], T],
    max_intentos: int,
    on_error: Callable[[Exception], None] | None = None,
) -> T | None:
    """Intenta `fn` hasta `max_intentos` veces; ante error llama `on_error`."""
    intento = 0
    while True:
        intento += 1
        try:
            return fn()
        except Exception as e:  # noqa: BLE001 - se re-lanzan tras agotar
            if on_error:
                on_error(e)
            if intento >= max_intentos:
                raise
            time.sleep(1)