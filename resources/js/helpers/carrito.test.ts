import { describe, expect, it } from 'vitest';
import {
    accionEscanear,
    agregarCantidadAlCarrito,
    normalizarPeso,
} from './carrito';
import type { ProductoVenta } from '@/types';

const producto = (unidad_medida: ProductoVenta['unidad_medida']): ProductoVenta => ({
    id: 1,
    nombre: 'Producto',
    codigo: '7790000000001',
    unidad_medida,
    categoria_id: 1,
    categoria_nombre: 'Frutas',
    imagen_url: null,
    precio: '1000.00',
});

describe('accionEscanear (agregado por scanner)', () => {
    it('UNIDAD agrega 1 al escanear', () => {
        expect(accionEscanear(producto('UNIDAD'))).toEqual({
            tipo: 'agregar',
            cantidad: 1,
        });
    });

    it('BOLSA agrega 1 al escanear', () => {
        expect(accionEscanear(producto('BOLSA'))).toEqual({
            tipo: 'agregar',
            cantidad: 1,
        });
    });

    it('KILOGRAMO solicita el peso en lugar de agregar', () => {
        expect(accionEscanear(producto('KILOGRAMO'))).toEqual({
            tipo: 'solicitar_peso',
        });
    });

    it('KILOGRAMO vuelve a solicitar el peso en un segundo escaneo', () => {
        const manzana = producto('KILOGRAMO');

        expect(accionEscanear(manzana)).toEqual({ tipo: 'solicitar_peso' });
        expect(accionEscanear(manzana)).toEqual({ tipo: 'solicitar_peso' });
    });

    it('KILOGRAMO no agrega nada si se cancela', () => {
        const manzana = producto('KILOGRAMO');
        const carrito = [{ producto: manzana, cantidad: 1.5 }];

        // Al cancelar no se invoca ninguna accion de agregar y el carrito no cambia.
        const accion = accionEscanear(manzana);

        expect(accion.tipo).toBe('solicitar_peso');
        expect(carrito).toEqual([{ producto: manzana, cantidad: 1.5 }]);
    });
});

describe('agregarCantidadAlCarrito', () => {
    it('UNIDAD escaneado repetido acumula +1', () => {
        const gaseosa = producto('UNIDAD');

        const unaVez = agregarCantidadAlCarrito([], gaseosa, 1);
        const dosVeces = agregarCantidadAlCarrito(unaVez, gaseosa, 1);

        expect(dosVeces).toHaveLength(1);
        expect(dosVeces[0]).toMatchObject({ producto: gaseosa, cantidad: 2 });
    });

    it('BOLSA escaneada repetida acumula +1', () => {
        const sopa = producto('BOLSA');

        const unaVez = agregarCantidadAlCarrito([], sopa, 1);
        const dosVeces = agregarCantidadAlCarrito(unaVez, sopa, 1);

        expect(dosVeces).toHaveLength(1);
        expect(dosVeces[0]).toMatchObject({ producto: sopa, cantidad: 2 });
    });

    it('no muta el carrito original', () => {
        const gaseosa = producto('UNIDAD');
        const original: ProductoVenta[] = [];

        const resultado = agregarCantidadAlCarrito([], gaseosa, 1);

        expect(original).toHaveLength(0);
        expect(resultado).toHaveLength(1);
    });
});

describe('normalizarPeso', () => {
    it.each([
        ['1', 1],
        ['1.5', 1.5],
        ['1.350', 1.35],
        ['1,350', 1.35],
        ['0,5', 0.5],
        [' 2.25 ', 2.25],
    ])('acepta %s y lo normaliza a %s', (entrada, esperado) => {
        expect(normalizarPeso(entrada)).toBe(esperado);
    });

    it.each(['', 'abc', '0', '-1', '1.5.5', '1,5,5', '0.0'])(
        'rechaza «%s»',
        (entrada) => {
            expect(normalizarPeso(entrada)).toBeNull();
        },
    );
});