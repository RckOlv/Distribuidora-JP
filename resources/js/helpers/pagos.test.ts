import { describe, expect, it } from 'vitest';
import { resolverPagoEfectivo, type FilaPago } from './pagos';

const efectivo = (monto: string): FilaPago => ({
    medio: 'EFECTIVO',
    monto,
});

describe('resolverPagoEfectivo — venta 100% en efectivo', () => {
    it('registra el pago por el total y calcula vuelto 0 cuando el efectivo es exacto', () => {
        const r = resolverPagoEfectivo([efectivo('7160')], 7160);

        expect(r.esSoloEfectivo).toBe(true);
        expect(r.efectivoRecibido).toBe(7160);
        expect(r.vuelto).toBe(0);
        expect(r.totalPagado).toBe(7160);
        expect(r.faltante).toBe(0);
        expect(r.excedente).toBe(0);
        expect(r.error).toBeNull();
        expect(r.puedeFinalizar).toBe(true);
        expect(r.pagosEnviar).toEqual([
            { medio_pago: 'EFECTIVO', monto: 7160 },
        ]);
        expect(r.efectivoRecibidoEnviar).toBe(7160);
    });

    it('calcula el vuelto y permite finalizar cuando el efectivo recibido supera el total', () => {
        const r = resolverPagoEfectivo([efectivo('10000')], 7160);

        expect(r.efectivoRecibido).toBe(10000);
        expect(r.vuelto).toBe(2840);
        expect(r.totalPagado).toBe(7160);
        expect(r.faltante).toBe(0);
        expect(r.excedente).toBe(0);
        expect(r.error).toBeNull();
        expect(r.puedeFinalizar).toBe(true);
        expect(r.pagosEnviar).toEqual([
            { medio_pago: 'EFECTIVO', monto: 7160 },
        ]);
        expect(r.efectivoRecibidoEnviar).toBe(10000);
    });

    it('no permite finalizar cuando el efectivo recibido es insuficiente', () => {
        const r = resolverPagoEfectivo([efectivo('5000')], 7160);

        expect(r.efectivoRecibido).toBe(5000);
        expect(r.vuelto).toBeNull();
        expect(r.totalPagado).toBe(5000);
        expect(r.faltante).toBe(2160);
        expect(r.puedeFinalizar).toBe(false);
        expect(r.error).toBeNull();
    });
});

describe('resolverPagoEfectivo — monto inválido en efectivo', () => {
    it('no permite finalizar y expone el error', () => {
        const r = resolverPagoEfectivo([efectivo('abc')], 7160);

        expect(r.montoInvalido).toBe(true);
        expect(r.error).toContain('importe válido');
        expect(r.puedeFinalizar).toBe(false);
    });

    it('trata el campo vacío como pendiente pera no bloquear con error de formato', () => {
        const r = resolverPagoEfectivo([efectivo('')], 7160);

        expect(r.efectivoRecibido).toBeNull();
        expect(r.montoInvalido).toBe(false);
        expect(r.puedeFinalizar).toBe(false);
        expect(r.error).toBeNull();
    });
});

describe('resolverPagoEfectivo — pagos múltiples', () => {
    it('mantiene el desglose exacto y permite finalizar cuando la suma coincide', () => {
        const pagos: FilaPago[] = [
            { medio: 'EFECTIVO', monto: '1000' },
            { medio: 'TRANSFERENCIA', monto: '700' },
        ];
        const r = resolverPagoEfectivo(pagos, 1700);

        expect(r.esSoloEfectivo).toBe(false);
        expect(r.efectivoRecibido).toBeNull();
        expect(r.vuelto).toBeNull();
        expect(r.totalPagado).toBe(1700);
        expect(r.faltante).toBe(0);
        expect(r.excedente).toBe(0);
        expect(r.error).toBeNull();
        expect(r.puedeFinalizar).toBe(true);
        expect(r.pagosEnviar).toEqual([
            { medio_pago: 'EFECTIVO', monto: 1000 },
            { medio_pago: 'TRANSFERENCIA', monto: 700 },
        ]);
        expect(r.efectivoRecibidoEnviar).toBeNull();
    });

    it('no permite finalizar cuando la suma de medios no cubre el total', () => {
        const pagos: FilaPago[] = [
            { medio: 'EFECTIVO', monto: '1000' },
            { medio: 'TRANSFERENCIA', monto: '500' },
        ];
        const r = resolverPagoEfectivo(pagos, 1700);

        expect(r.totalPagado).toBe(1500);
        expect(r.faltante).toBe(200);
        expect(r.puedeFinalizar).toBe(false);
    });

    it('no permite finalizar cuando los pagos superan el total en una venta mixta', () => {
        const pagos: FilaPago[] = [
            { medio: 'EFECTIVO', monto: '1000' },
            { medio: 'TRANSFERENCIA', monto: '900' },
        ];
        const r = resolverPagoEfectivo(pagos, 1700);

        expect(r.excedente).toBe(200);
        expect(r.error).toContain('superan el total');
        expect(r.puedeFinalizar).toBe(false);
    });

    it('trata una venta 100% electrónica como múltiple sin recibido ni vuelto', () => {
        const pagos: FilaPago[] = [
            { medio: 'TARJETA', monto: '1700' },
        ];
        const r = resolverPagoEfectivo(pagos, 1700);

        expect(r.esSoloEfectivo).toBe(false);
        expect(r.efectivoRecibido).toBeNull();
        expect(r.vuelto).toBeNull();
        expect(r.puedeFinalizar).toBe(true);
        expect(r.pagosEnviar).toEqual([
            { medio_pago: 'TARJETA', monto: 1700 },
        ]);
        expect(r.efectivoRecibidoEnviar).toBeNull();
    });
});

describe('resolverPagoEfectivo — redondeo a 2 decimales', () => {
    it('redondea el vuelto con 2 decimales', () => {
        const r = resolverPagoEfectivo([efectivo('10000.5')], 7160.33);

        expect(r.vuelto).toBe(2840.17);
        expect(r.totalPagado).toBe(7160.33);
        expect(r.pagosEnviar).toEqual([
            { medio_pago: 'EFECTIVO', monto: 7160.33 },
        ]);
    });
});