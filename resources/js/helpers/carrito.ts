import type { ItemCarrito, ProductoVenta } from '@/types';

export const esUnidadKilogramo = (producto: ProductoVenta): boolean =>
    producto.unidad_medida === 'KILOGRAMO';

export type AccionEscaneo =
    | { tipo: 'solicitar_peso' }
    | { tipo: 'agregar'; cantidad: number };

/**
 * Decide qué hacer al escanear/agregar un producto.
 *
 * UNIDAD y BOLSA se agregan sumando +1; KILOGRAMO siempre solicita el peso
 * (nunca acumula kilogramos automáticamente).
 */
export const accionEscanear = (producto: ProductoVenta): AccionEscaneo =>
    esUnidadKilogramo(producto)
        ? { tipo: 'solicitar_peso' }
        : { tipo: 'agregar', cantidad: 1 };

/**
 * Normaliza el peso ingresado (acepta coma o punto decimal) y valida que sea
 * un número mayor que cero con hasta 3 decimales.
 *
 * @returns Peso en kilogramos o null si el valor no es válido.
 */
export const normalizarPeso = (texto: string): number | null => {
    const normalizado = texto.trim().replace(',', '.');

    if (!/^\d+(?:\.\d{1,3})?$/.test(normalizado)) {
        return null;
    }

    const valor = Number(normalizado);

    if (!Number.isFinite(valor) || valor <= 0) {
        return null;
    }

    return Math.round(valor * 1000) / 1000;
};

/**
 * Agrega cantidad de un producto al carrito.
 *
 * Si el producto ya existe, suma la cantidad (grupo por producto).
 * Devuelve un carrito nuevo sin mutar el original.
 */
export const agregarCantidadAlCarrito = (
    carrito: ItemCarrito[],
    producto: ProductoVenta,
    cantidad: number,
): ItemCarrito[] => {
    const existente = carrito.find((item) => item.producto.id === producto.id);

    if (existente === undefined) {
        return [...carrito, { producto, cantidad }];
    }

    return carrito.map((item) =>
        item === existente
            ? {
                  ...item,
                  cantidad: Math.round((item.cantidad + cantidad) * 1000) / 1000,
              }
            : item,
    );
};