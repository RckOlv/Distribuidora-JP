export const MONTO_MAX = 9999999999.99;

export const MAX_LONGITUD_MONTO = 13;

export const normalizarMonto = (texto: string): number | null => {
    const limpio = texto.trim().replace(',', '.');

    if (limpio === '') {
        return null;
    }

    if (!/^\d+(?:\.\d{0,2})?$/.test(limpio)) {
        return null;
    }

    const numero = Number(limpio);

    if (!Number.isFinite(numero) || numero <= 0 || numero > MONTO_MAX) {
        return null;
    }

    return Math.round(numero * 100) / 100;
};

/**
 * Sanea un importe digitado/pegado en el POS dejando solo una escritura
 * numérica válida: dígitos con un único separador decimal, hasta 10 enteros
 * y 2 decimales (tope decimal(12,2)).
 *
 * Se aplica mientras se escribe y también sobre el texto pegado, para que
 * nunca quede algo como "abc2000" o "12.300,45a".
 *
 * Convención argentina: si conviven coma y punto, la coma es el decimal y
 * los puntos se descartan como separadores de miles ("2.000,50" → "2000,50").
 */
export const sanitizarMonto = (texto: string): string => {
    const sinInvalidos = texto.replace(/[^0-9.,]/g, '');

    const posicion = posicionSeparadorDecimal(sinInvalidos);

    if (posicion === -1) {
        return sinInvalidos.slice(0, 10);
    }

    const separador = sinInvalidos[posicion];
    let enteros = sinInvalidos
        .slice(0, posicion)
        .replace(/[.,]/g, '')
        .slice(0, 10);
    let decimales = sinInvalidos
        .slice(posicion + 1)
        .replace(/[.,]/g, '')
        .slice(0, 2);

    if (decimales === '') {
        return enteros;
    }

    if (enteros === '') {
        enteros = '0';
    }

    return `${enteros}${separador}${decimales}`;
};

const posicionSeparadorDecimal = (texto: string): number => {
    if (texto.includes(',') && texto.includes('.')) {
        return texto.indexOf(',');
    }

    return texto.search(/[.,]/);
};