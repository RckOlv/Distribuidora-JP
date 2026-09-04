"""Helpers de testing para validar bytes ESC/POS capturados por el simulador.

No es un intérprete perfecto de la TP95 real, sino una herramienta de
depuración capaz de:

  * tolerar comandos ESC/POS intercalados con texto,
  * extraer una representación de texto legible (renderer simple),
  * detectar fragmentos / campos relevantes del ticket,
  * comprobar la presencia de comandos (inicialización, corte, etc.).

El generador real (``escpos.py``) emite texto intercalado con bytes de control
(alineación, negrita, doble tamaño, avance de línea...). Este parser los
reconoce por secuencia y los descarta, conservando el texto imprimible y la
estructura de líneas.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

# Secuencias de control emitidas por escpos.py (ESCPOS conocidas).
_SKIP: list[bytes] = [
    b"\x1b(t\x05\x000",  # SELECT_CHARMAP
    b"\x1bE\x01",  # NEGRITA_ON
    b"\x1bE\x00",  # NEGRITA_OFF
    b"\x1ba\x00",  # ALINEAR_IZQ
    b"\x1ba\x01",  # ALINEAR_CENTRO
    b"\x1ba\x02",  # ALINEAR_DER
    b"\x1d!\x11",  # TAMANO_DOBLE
    b"\x1d!\x00",  # SELECCIONAR_MODO
    b"\x1bd\x01",  # AVANZAR_LINEAS
    b"\x1dV\x41\x00",  # CORTE
]

_CORTE = b"\x1dV\x41\x00"


def _despejar(datos: bytes) -> bytes:
    """Elimina todas las secuencias de control conocidas del buffer."""
    salida = bytearray(datos)
    for seq in _SKIP:
        salida = bytearray(salida.replace(seq, b""))
    return bytes(salida)


def texto_visible(datos: bytes) -> str:
    """Representación de texto del ticket (solo caracteres imprimibles).

    Descarta comandos ESC/POS conocidos, mantiene el orden de las líneas y
    conserva los caracteres españoles (mapa CP-850 → latin-1).
    """
    limpio = _despejar(datos)
    partes: list[str] = []
    buffer: list[str] = []
    for byte in limpio:
        b = byte
        if b == 0x0A:  # LF -> fin de línea
            if buffer:
                partes.append("".join(buffer))
                buffer = []
            continue
        if b == 0x0D:  # CR -> ignorar
            continue
        # Acepta imprimibles ASCII y los bytes >= 0xA0 de CP-850 (acentuados).
        if 0x20 <= b < 0x7F or b >= 0xA0:
            buffer.append(chr(b))
        else:
            # Otro control: separador (espacio virtual) sin romper la línea.
            buffer.append(" ")
    if buffer:
        partes.append("".join(buffer))
    return "\n".join(partes).strip("\n")


def lineas(datos: bytes) -> list[str]:
    """Devuelve las líneas de texto del ticket (sin líneas vacías)."""
    return [ln for ln in texto_visible(datos).split("\n") if ln.strip() != ""]


def contiene(datos: bytes, fragmento: str) -> bool:
    """¿El ticket contiene el fragmento de texto (ignorando mayúsculas)?"""
    return fragmento.lower() in texto_visible(datos).lower()


def hay_corte(datos: bytes) -> bool:
    """¿El buffer incluye el comando ESC/POS de corte total?"""
    return _CORTE in datos


def contar_cortes(datos: bytes) -> int:
    return datos.count(_CORTE)


def hay_inicializacion(datos: bytes) -> bool:
    """¿El buffer incluye la selección de tablero de caracteres (charmap)?"""
    return b"\x1b(t\x05\x000" in datos


def contiene_marca_reimpresion(datos: bytes) -> bool:
    return contiene(datos, "REIMPRESION")


def es_reimpresion(datos: bytes) -> bool:
    return contiene_marca_reimpresion(datos)


def es_venta(datos: bytes) -> bool:
    return not contiene_marca_reimpresion(datos)


@dataclass
class TicketExtraido:
    """Resultado del parser simple de un ticket."""

    texto: str
    lineas: list[str] = field(default_factory=list)
    comercio: str = ""
    numero_ticket: str = ""
    fecha: str = ""
    pago: str = ""
    total: str = ""
    items: list[tuple[str, str, str, str]] = field(default_factory=list)
    reimpresion: bool = False

    def contiene(self, fragmento: str) -> bool:
        return fragmento.lower() in self.texto.lower()

    def item_con_nombre(self, nombre: str) -> bool:
        # El nombre del producto se imprime en una línea propia, mientras que
        # cantidad/precio/subtotal van en la línea siguiente. Validamos la
        # presencia del nombre en el texto global del ticket.
        return nombre.lower() in self.texto.lower()


def extraer_ticket(datos: bytes) -> TicketExtraido:
    """Parsea un ticket y devuelve campos estructurados (para validación).

    Los items se detectan por el patrón ``cantidad x precio  subtotal`` de la
    columna generada por ``escpos.fila_alineada`` (izquierda/derecha separadas
    por varios espacios).
    """
    ln = lineas(datos)
    extraido = TicketExtraido(texto="\n".join(ln), lineas=ln)

    extraido.reimpresion = contiene_marca_reimpresion(datos)

    for i, linea in enumerate(ln):
        if "TICKET N" in linea.upper():
            extraido.numero_ticket = _derecha(linea)
        elif linea.upper().startswith("FECHA"):
            extraido.fecha = _derecha(linea)
        elif linea.upper().startswith("PAGO"):
            extraido.pago = _derecha(linea).strip()
        elif linea.upper().startswith("TOTAL"):
            extraido.total = _derecha(linea)
        elif i == 0:
            extraido.comercio = linea.strip()

    # Items: patrón "<cantidad/unidad> x <precio>  <subtotal>"
    for linea in ln:
        m = re.search(r"^(.+?)\s+x\s+(\$\s?[\d.,]+)\s{2,}(\$\s?[\d.,]+)$", linea)
        if m:
            extraido.items.append((m.group(1).strip(), m.group(2), m.group(3), linea))

    return extraido


def _derecha(linea: str) -> str:
    """Toma el valor de una fila 'etiqueta   valor' (la parte derecha)."""
    partes = re.split(r"\s{2,}", linea.strip(), maxsplit=1)
    return partes[-1].strip() if len(partes) > 1 else ""


if __name__ == "__main__":
    # Uso interactivo de depuración: leer bytes desde un archivo opcional.
    import sys

    ruta = sys.argv[1] if len(sys.argv) > 1 else None
    if not ruta:
        print("Uso: python -m tests.escpos_validator <archivo.bin>")
        sys.exit(0)
    with open(ruta, "rb") as f:
        t = extraer_ticket(f.read())
    print("=" * 40)
    print(t.texto)
    print("=" * 40)
    print(f"comercio={t.comercio!r} numero={t.numero_ticket!r} pago={t.pago!r}")
    print(f"total={t.total!r} reimpresion={t.reimpresion} items={len(t.items)}")
