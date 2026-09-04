import { describe, expect, it } from 'vitest';
import {
    formatoFechaLegible,
    rangoHoy,
    rangoMesActual,
    rangoSemanaActual,
} from './fechas';

// Las fechas son fijas y se construyen en UTC; los rangos deben respetar
// America/Argentina/Buenos_Aires (UTC-3), por lo que los días son los del
// calendario en esa zona.
const aLas = (iso: string, horas = 12): Date =>
    new Date(`${iso}T${String(horas).padStart(2, '0')}:00:00.000Z`);

describe('rangoHoy', () => {
    it('devolvce el mismo día para desde y hasta', () => {
        const rango = rangoHoy(aLas('2026-09-04'));
        expect(rango).toEqual({ desde: '2026-09-04', hasta: '2026-09-04' });
    });

    it('respeta la medianoche de la zona (UTC-3)', () => {
        const justoAntes = rangoHoy(aLas('2026-09-04', 2)); // 02:00 UTC = 23:00 del 03/09 en AR
        expect(justoAntes.desde).toBe('2026-09-03');

        const justoDespues = rangoHoy(aLas('2026-09-04', 3)); // 03:00 UTC = 00:00 del 04/09 en AR
        expect(justoDespues.desde).toBe('2026-09-04');
    });
});

describe('rangoSemanaActual', () => {
    it('devuelve lunes a domingo para un miércoles', () => {
        const rango = rangoSemanaActual(aLas('2026-09-10')); // jueves
        // El 2026-09-10 es un jueves; la semana es 07/09 (lunes) a 13/09 (domingo).
        expect(rango).toEqual({ desde: '2026-09-07', hasta: '2026-09-13' });
    });

    it('devuelve la semana completa para un domingo', () => {
        const rango = rangoSemanaActual(aLas('2026-09-13')); // domingo
        expect(rango).toEqual({ desde: '2026-09-07', hasta: '2026-09-13' });
    });

    it('devuelve la semana completa para un lunes', () => {
        const rango = rangoSemanaActual(aLas('2026-09-07')); // lunes
        expect(rango).toEqual({ desde: '2026-09-07', hasta: '2026-09-13' });
    });
});

describe('rangoMesActual', () => {
    it('devuelve el primer y último día del mes', () => {
        const rango = rangoMesActual(aLas('2026-09-04'));
        expect(rango).toEqual({ desde: '2026-09-01', hasta: '2026-09-30' });
    });

    it('respeta meses de 31 días y sobre el límite de año', () => {
        expect(rangoMesActual(aLas('2026-01-15'))).toEqual({
            desde: '2026-01-01',
            hasta: '2026-01-31',
        });
        expect(rangoMesActual(aLas('2026-12-10'))).toEqual({
            desde: '2026-12-01',
            hasta: '2026-12-31',
        });
    });
});

describe('formatoFechaLegible', () => {
    it('formatea YYYY-MM-DD a DD/MM/YYYY', () => {
        expect(formatoFechaLegible('2026-09-04')).toBe('04/09/2026');
    });

    it('devuelve guión para null o vacío', () => {
        expect(formatoFechaLegible(null)).toBe('—');
        expect(formatoFechaLegible(undefined)).toBe('—');
    });
});