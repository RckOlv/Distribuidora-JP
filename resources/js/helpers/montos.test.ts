import { describe, expect, it } from 'vitest';
import { MONTO_MAX, normalizarMonto, sanitizarMonto } from './montos';

describe('normalizarMonto', () => {
    it.each([
        ['2000', 2000],
        ['2000,50', 2000.5],
        ['2000.50', 2000.5],
        ['3200,5', 3200.5],
        ['0.50', 0.5],
        [' 2500 ', 2500],
    ])('acepta %s y lo normaliza a %s', (entrada, esperado) => {
        expect(normalizarMonto(entrada)).toBe(esperado);
    });

    it.each([
        '',
        'abc',
        'a2000',
        '2000abc',
        '2000,505',
        '2000.555',
        '-2000',
        '2.000,50',
        '12.300,45',
        '0',
        '0.00',
        '10000000000',
        `${MONTO_MAX + 1}`,
    ])('rechaza el importe «%s»', (entrada) => {
        expect(normalizarMonto(entrada)).toBeNull();
    });
});

describe('sanitizarMonto', () => {
    it.each([
        ['', ''],
        ['2000', '2000'],
        ['2000,50', '2000,50'],
        ['2000.50', '2000.50'],
        ['abc', ''],
        ['a2000', '2000'],
        ['2000abc', '2000'],
        ['2 000', '2000'],
        ['$3200,50', '3200,50'],
        ['-2000', '2000'],
        ['2000,505', '2000,50'],
        ['2000.555', '2000.55'],
        ['abc2000', '2000'],
        ['2.000,50', '2000,50'],
        ['12.300,45', '12300,45'],
        ['1.5.5', '1.55'],
        [',50', '0,50'],
    ])('«%s» queda como «%s»', (entrada, esperado) => {
        expect(sanitizarMonto(entrada)).toBe(esperado);
    });

    it('recorta a 10 enteros y 2 decimales (tope decimal(12,2))', () => {
        expect(sanitizarMonto('99999999999999,999')).toBe('9999999999,99');
    });
});