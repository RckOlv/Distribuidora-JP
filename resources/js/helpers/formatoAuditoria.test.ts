import { describe, expect, it } from 'vitest';
import {
    cambiosDe,
    camposDe,
    etiquetaCampo,
    formatearValor,
    valorEnumLegible,
} from './formatoAuditoria';

describe('etiquetaCampo', () => {
    it('traduce claves técnicas frecuentes a etiquetas legibles', () => {
        expect(etiquetaCampo('monto')).toBe('Monto');
        expect(etiquetaCampo('caja_id')).toBe('Caja');
        expect(etiquetaCampo('medio_pago')).toBe('Medio de pago');
        expect(etiquetaCampo('unidad_medida')).toBe('Unidad de medida');
        expect(etiquetaCampo('precio_nuevo')).toBe('Precio de venta');
        expect(etiquetaCampo('producto_id')).toBe('Producto');
    });

    it('convierte snake_case desconocido a texto humano', () => {
        expect(etiquetaCampo('stock_minimo')).toBe('Stock minimo');
        expect(etiquetaCampo('fecha_ultima_venta')).toBe('Fecha ultima venta');
    });
});

describe('valorEnumLegible', () => {
    it('traduce enums conocidos', () => {
        expect(valorEnumLegible('EFECTIVO')).toBe('Efectivo');
        expect(valorEnumLegible('DUENO')).toBe('Dueño');
        expect(valorEnumLegible('KILOGRAMO')).toBe('Kilogramo');
        expect(valorEnumLegible('ABIERTA')).toBe('Abierta');
    });

    it('transforma enums desconocidos de forma legible', () => {
        expect(valorEnumLegible('STOCK_BAJO')).toBe('Stock bajo');
    });
});

describe('formatearValor', () => {
    it('formatea booleanos como Sí/No', () => {
        expect(formatearValor('activo', true)).toMatchObject({
            texto: 'Sí',
            tipo: 'booleano',
            booleano: true,
        });
        expect(formatearValor('activo', false)).toMatchObject({
            texto: 'No',
            tipo: 'booleano',
            booleano: false,
        });
    });

    it('muestra null como "Sin valor"', () => {
        expect(formatearValor('descripcion', null)).toMatchObject({
            texto: 'Sin valor',
            tipo: 'nulo',
        });
        expect(formatearValor('descripcion', undefined)).toMatchObject({
            texto: 'Sin valor',
            tipo: 'nulo',
        });
    });

    it('formatea montos como moneda argentina', () => {
        expect(formatearValor('monto', 1000).texto).toContain('1.000,00');
        expect(formatearValor('monto', 1000).tipo).toBe('moneda');
        expect(formatearValor('total', 2500).texto).toContain('2.500,00');
    });

    it('formatea IDs con #', () => {
        expect(formatearValor('caja_id', 4)).toEqual({
            texto: '#4',
            tipo: 'id',
            booleano: null,
        });
        expect(formatearValor('movimiento_id', 4)).toEqual({
            texto: '#4',
            tipo: 'id',
            booleano: null,
        });
    });

    it('formatea la caja física con nombre legible', () => {
        expect(formatearValor('caja_fisica_id', 1).texto).toBe('Caja 1');
    });

    it('formatea fechas ISO a formato legible', () => {
        const valor = formatearValor('abierta_en', '2026-01-01T12:00:00-03:00');
        expect(valor.tipo).toBe('fecha');
        expect(valor.texto).not.toBe('2026-01-01T12:00:00-03:00');
    });

    it('traduce enums dentro de valores', () => {
        expect(formatearValor('medio_pago', 'TRANSFERENCIA').texto).toBe(
            'Transferencia',
        );
        expect(formatearValor('tipo', 'INGRESO').texto).toBe('Ingreso');
        expect(formatearValor('rol', 'CAJERO').texto).toBe('Cajero');
    });

    it('deja los textos simples tal cual', () => {
        expect(formatearValor('nombre', 'Banana Premium').texto).toBe(
            'Banana Premium',
        );
        expect(formatearValor('concepto', 'Cambio').texto).toBe('Cambio');
    });

    it('no pierde información con valores desconocidos', () => {
        expect(formatearValor('propiedad_extra', 'algo').texto).toBe('algo');
        expect(formatearValor('numero_arbitrario', 42).texto).toBe('42');
    });
});

describe('camposDe', () => {
    it('devuelve un campo por cada propiedad del JSON sin perder información', () => {
        const datos = {
            tipo: 'INGRESO',
            monto: 1000,
            caja_id: 1,
            concepto: 'Cambio',
            movimiento_id: 4,
            caja_fisica_id: 1,
        };

        const campos = camposDe(datos);

        expect(campos).toHaveLength(6);
        expect(campos.map((campo) => campo.clave).sort()).toEqual(
            Object.keys(datos).sort(),
        );

        const porClave = Object.fromEntries(campos.map((campo) => [campo.clave, campo]));
        expect(porClave.tipo.valor.texto).toBe('Ingreso');
        expect(porClave.monto.valor.texto).toContain('1.000,00');
        expect(porClave.caja_id.valor.texto).toBe('#1');
        expect(porClave.caja_fisica_id.valor.texto).toBe('Caja 1');
        expect(porClave.movimiento_id.valor.texto).toBe('#4');
        expect(porClave.concepto.valor.texto).toBe('Cambio');
    });

    it('maneja valores nulos y booleanos', () => {
        const campos = camposDe({
            nombre: 'Banana',
            activo: true,
            descripcion: null,
        });

        const porClave = Object.fromEntries(campos.map((campo) => [campo.clave, campo]));
        expect(porClave.activo.valor.texto).toBe('Sí');
        expect(porClave.descripcion.valor.texto).toBe('Sin valor');
    });

    it('devuelve lista vacía para null', () => {
        expect(camposDe(null)).toEqual([]);
    });
});

describe('cambiosDe', () => {
    it('muestra diferencias de booleanos de forma amigable', () => {
        const cambios = cambiosDe({ activo: false }, { activo: true });

        expect(cambios).toHaveLength(1);
        expect(cambios[0].etiqueta).toBe('Activo');
        expect(cambios[0].anterior.texto).toBe('No');
        expect(cambios[0].nuevo.texto).toBe('Sí');
    });

    it('muestra diferencias de montos', () => {
        const cambios = cambiosDe(
            { precio_anterior: 2000 },
            { precio_nuevo: 2500 },
        );

        expect(cambios).toHaveLength(1);
        expect(cambios[0].etiqueta).toBe('Precio de venta');
        expect(cambios[0].anterior.texto).toContain('2.000,00');
        expect(cambios[0].nuevo.texto).toContain('2.500,00');
    });

    it('incluye valores solo presentes en un lado sin perder información', () => {
        const cambios = cambiosDe(
            { nombre: 'Banana', precio_anterior: 2000 },
            { nombre: 'Banana Premium', precio_nuevo: 2500, activo: true },
        );

        const porClave = Object.fromEntries(cambios.map((cambio) => [cambio.clave, cambio]));

        expect(porClave.nombre.anterior.texto).toBe('Banana');
        expect(porClave.nombre.nuevo.texto).toBe('Banana Premium');
        expect(porClave.precio.anterior.texto).toContain('2.000,00');
        expect(porClave.precio.nuevo.texto).toContain('2.500,00');
        expect(porClave.activo.anterior.texto).toBe('Sin valor');
        expect(porClave.activo.nuevo.texto).toBe('Sí');
    });
});