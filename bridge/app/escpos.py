"""Generación de comandos ESC/POS para impresora térmica 80 mm.

Generamos los BYTES directamente (sin HTML ni PDF). La codificación de
caracteres españoles se centraliza aquí: las impresoras térmicas suelen
trabajar con Code Page 437 / 850, no UTF-8. Como todavía no disponemos de la
Unnion TP95 física, se traduce áéíóúñ de forma explícita y queda documentado
que podría ajustarse (CP-850 vs CP-437) cuando se valide con el equipo real.
"""

from __future__ import annotations

# Comandos ESC/POS (bytes)
ESC = b"\x1b"
GS = b"\x1d"
LF = b"\x0a"
# Selecta la tabla de caracteres (PC437 por defecto).
SELECT_CHARMAP = ESC + b"(t\x05\x00" + b"0"
# Estilos de texto
NEGRITA_ON = ESC + b"E\x01"
NEGRITA_OFF = ESC + b"E\x00"
ALINEAR_IZQ = ESC + b"a\x00"
ALINEAR_CENTRO = ESC + b"a\x01"
ALINEAR_DER = ESC + b"a\x02"
SELECCIONAR_MODO = GS + b"!\x00"  # normal (tamaño)
TAMANO_DOBLE = GS + b"!\x11"      # ancho+alto doble
AVANZAR_LINEAS = ESC + b"d\x01"  # alimentación
CORTE = GS + b"V\x41\x00"         # corte total

# Ancho del papel en columnas de caracteres (80 mm, fuente normal).
ANCHO = 48


class CodificacionEspanyol:
    """Capa de traducción de acentos/tildes para ESC/POS.

    Sincronizado con lo que la impresora interprete; por defecto se usan los
    puntos de código de PC850 (acepta más caracteres que PC437). Cambiar a
    PC437 desactivando SELECT_CHARMAP === si el equipo físico así lo requiere.
    """

    MAPA: dict[int, int] = {
        0x00E1: 0xA0,  # á
        0x00E9: 0x82,  # é
        0x00ED: 0xA1,  # í
        0x00F3: 0xA2,  # ó
        0x00FA: 0xA3,  # ú
        0x00C1: 0xB5,  # Á
        0x00C9: 0x90,  # É
        0x00CD: 0xD6,  # Í
        0x00D3: 0xE0,  # Ó
        0x00DA: 0xE9,  # Ú
        0x00F1: 0xA4,  # ñ
        0x00D1: 0xA5,  # Ñ
        0x00FC: 0x81,  # ü
        0x00DC: 0x9A,  # Ü
        0x00BF: 0xA6,  # ¿
        0x00A1: 0xAD,  # ¡
        0x00E4: 0x84,  # ä (por si acaso)
        0x00F6: 0x94,  # ö
    }

    @classmethod
    def codificar(cls, texto: str) -> bytes:
        salida = bytearray()
        for caracter in texto:
            codigo = ord(caracter)
            if codigo in cls.MAPA:
                salida.append(cls.MAPA[codigo])
            elif codigo < 256:
                salida.append(codigo)
            else:
                # Carácter fuera de la tabla: se descarta sin romper el buffer.
                continue
        return bytes(salida)


class EscPos:
    """Acumula el contenido binario del ticket y lo emite."""

    # Constantes de alineación expuestas como atributos de clase.
    ALINEAR_IZQ = ALINEAR_IZQ
    ALINEAR_CENTRO = ALINEAR_CENTRO
    ALINEAR_DER = ALINEAR_DER

    def __init__(self, ancho: int = ANCHO) -> None:
        self.ancho = ancho
        self._buffer = bytearray()
        self._buffer += SELECT_CHARMAP

    # --- primitivas ---

    def texto(self, texto: str, negrita: bool = False, doble: bool = False,
              alinear: bytes = ALINEAR_IZQ) -> "EscPos":
        if doble:
            self._buffer += TAMANO_DOBLE
        elif negrita:
            self._buffer += NEGRITA_ON
        self._buffer += alinear
        self._buffer += CodificacionEspanyol.codificar(texto)
        self._buffer += LF
        if doble:
            self._buffer += GS + b"!\x00"
        elif negrita:
            self._buffer += NEGRITA_OFF
        return self

    def linea(self, texto: str = "", char: str = "-", alinear: bytes | None = None) -> "EscPos":
        relleno = (char * self.ancho)
        if texto:
            relleno = texto.center(self.ancho, char)
        alinear = alinear or ALINEAR_IZQ
        self._buffer += alinear
        self._buffer += CodificacionEspanyol.codificar(relleno[: self.ancho])
        self._buffer += LF
        return self

    def fila_dos_columnas(self, izquierda: str, derecha: str, negrita: bool = False) -> "EscPos":
        espacio = max(1, self.ancho - len(izquierda) - len(derecha))
        fila = izquierda + (" " * espacio) + derecha
        self.texto(fila, negrita=negrita)
        return self

    def fila_alineada(self, izquierda: str, derecha: str) -> "EscPos":
        """Columna cantidad+unidad a la izquierda y monto alineado a la derecha."""
        return self.fila_dos_columnas(izquierda, derecha)

    def separador(self) -> "EscPos":
        return self.linea("")

    def alimentar(self, lineas: int = 2) -> "EscPos":
        for _ in range(lineas):
            self._buffer += LF
        return self

    def cortar(self) -> "EscPos":
        self._buffer += CORTE
        return self

    def bytes(self) -> bytes:
        """Bytes completos listos para enviar a la impresora."""
        return bytes(self._buffer)


# ----------------------------------------------------------------------------
# Formato de cantidad según unidad
# ----------------------------------------------------------------------------

def formatear_cantidad(cantidad: float, unidad: str) -> str:
    """KILOGRAMO: hasta 3 decimales; UNIDAD/BOLSA: entero."""
    unidad_lower = (unidad or "").lower()

    if unidad_lower in ("kilogramo", "kg"):
        valores = (f"{cantidad:.3f}".rstrip("0")).rstrip(".")
        return f"{valores} kg"

    # Unidad / bolsa
    etiqueta = unidad_lower or "unidad"
    entero = int(round(float(cantidad)))
    return f"{entero} {etiqueta}"


def formatear_moneda(valor) -> str:
    """$ 1.234,50 legible para papel térmico."""
    import locale

    try:
        locale.setlocale(locale.LC_ALL, "")
    except locale.Error:
        pass
    try:
        monto = float(valor)
        miles = f"{monto:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
        return f"$ {miles}"
    except (TypeError, ValueError):
        return f"$ {valor}"