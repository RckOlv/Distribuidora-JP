"""Construye el ticket ESC/POS a partir del snapshot JSON del ticket de Laravel.

NO reconstruye la venta consultando productos: usa el snapshot ya emitido en
la Fase 5A (precios y cantidades históricas).
"""

from __future__ import annotations

import json
from typing import Any

from app.escpos import EscPos, formatear_cantidad, formatear_moneda


class ErrorPermanente(Exception):
    """Error de datos/formato que no se resolverá reintentando la impresión.

    Ej.: snapshot inexistente, JSON corrupto, estructura de ticket inválida,
    montos no numéricos. El trabajo debe quedar en ERROR y NO reintentarse.
    """


class TicketSnapshot:
    def __init__(self, contenido: dict[str, Any]) -> None:
        self.contenido = contenido

    # Accesos tipados sobre el snapshot
    def numero(self) -> str:
        return str(self.contenido.get("numero", ""))

    def fecha(self) -> str:
        return str(self.contenido.get("fecha", ""))

    def medio_pago(self) -> str:
        return str(self.contenido.get("medio_pago", ""))

    def total(self) -> Any:
        return self.contenido.get("total", 0)

    def detalles(self) -> list[dict[str, Any]]:
        return list(self.contenido.get("detalles", []))

    def comercio(self) -> dict[str, Any]:
        return self.contenido.get("comercio", {}) or {}

    def validar(self) -> None:
        """Valida el snapshot y levanta ErrorPermanente si es inutilizable.

        No valida valores que puedan variar legítimamente (impresoras apagadas
        se resuelven reintentando): solo lo estructural que jamás mejorará.
        """
        if not isinstance(self.contenido, dict):
            raise ErrorPermanente(
                "Snapshot inválido: no es un objeto JSON (estructura corrupta)."
            )
        if not isinstance(self.detalles(), list):
            raise ErrorPermanente(
                "Snapshot inválido: 'detalles' no es una lista."
            )
        # 'total' debe poder interpretarse como número para imprimir el total.
        total = self.contenido.get("total", 0)
        try:
            float(str(total))
        except (TypeError, ValueError):
            raise ErrorPermanente(
                f"Snapshot inválido: 'total' no es un número ({total!r})."
            ) from None


def generar_ticket(snapshot: TicketSnapshot) -> bytes:
    """Genera los bytes ESC/POS para un ticket real de venta."""
    snapshot.validar()
    esc = EscPos()
    # cabecera del comercio
    esc.texto(str(snapshot.comercio().get("nombre", "Mi Verdulería")).upper(),
              negrita=True, doble=True, alinear=EscPos.ALINEAR_CENTRO)
    direccion = str(snapshot.comercio().get("direccion", "")).strip()
    telefono = str(snapshot.comercio().get("telefono", "")).strip()
    if direccion:
        esc.linea(direccion, char=" ", alinear=EscPos.ALINEAR_CENTRO)
    if telefono:
        esc.linea(telefono, char=" ", alinear=EscPos.ALINEAR_CENTRO)
    esc.separador()

    # ticket / fecha
    esc.fila_alineada("TICKET N°", snapshot.numero())
    esc.fila_alineada("Fecha", snapshot.fecha())
    esc.fila_alineada("Pago", snapshot.medio_pago())
    esc.separador()

    # detalles de productos (columnas alineadas)
    for i, detalle in enumerate(snapshot.detalles(), start=1):
        if not isinstance(detalle, dict):
            raise ErrorPermanente(f"Detalle {i} inválido: no es un objeto.")
        nombre = str(detalle.get("nombre", ""))
        try:
            cantidad = formatear_cantidad(
                float(str(detalle.get("cantidad", 0)) or 0),
                str(detalle.get("unidad_medida", "UNIDAD")),
            )
        except (TypeError, ValueError):
            raise ErrorPermanente(
                f"Detalle {i}: 'cantidad' no interpretable "
                f"({detalle.get('cantidad')!r})."
            ) from None
        try:
            precio = formatear_moneda(float(str(detalle.get("precio_unitario", 0)) or 0))
            subtotal = formatear_moneda(float(str(detalle.get("subtotal", 0)) or 0))
        except (TypeError, ValueError):
            raise ErrorPermanente(
                f"Detalle {i}: monto no interpretable "
                f"(precio={detalle.get('precio_unitario')!r}, "
                f"subtotal={detalle.get('subtotal')!r})."
            ) from None

        esc.texto(nombre)
        esc.fila_alineada(f"{cantidad}  x {precio}", subtotal)
    esc.separador()

    # total
    esc.fila_alineada("TOTAL", formatear_moneda(snapshot.total()))

    # leyenda de agradecimiento
    leyenda = str(snapshot.comercio().get("leyenda", "Gracias por su compra")).strip()
    if leyenda:
        esc.alimentar(1)
        esc.texto(leyenda, alinear=EscPos.ALINEAR_CENTRO)

    esc.alimentar(2)
    esc.cortar()
    return esc.bytes()