import type { MedioPago } from '@/types';
import { normalizarMonto } from './montos';

export interface FilaPago {
    medio: MedioPago;
    monto: string;
}

export interface PagoEnvio {
    medio_pago: MedioPago;
    monto: number;
}

export interface ResolucionPagos {
    esSoloEfectivo: boolean;
    efectivoRecibido: number | null;
    totalPagado: number;
    faltante: number;
    excedente: number;
    vuelto: number | null;
    montoInvalido: boolean;
    error: string | null;
    puedeFinalizar: boolean;
    pagosEnviar: PagoEnvio[];
    efectivoRecibidoEnviar: number | null;
}

const redondear = (monto: number): number => Math.round(monto * 100) / 100;

/**
 * Resuelve el desglose de pagos del POS a partir de las filas ingresadas.
 *
 * En una venta 100% en efectivo (una sola fila, medio EFECTIVO) el monto de
 * la fila representa el EFECTIVO RECIBIDO: se permite que supere el total, se
 * calcula el vuelto ({@link vuelto} = recibido − total) y el pago que se
 * registra en el payload es siempre el total de la venta.
 *
 * En ventas mixtas o con medios electrónicos cada medio cubre su parte exacta
 * del total: no hay recibido ni vuelto, y se envía el desglose tal cual.
 */
export const resolverPagoEfectivo = (
    pagos: FilaPago[],
    total: number,
): ResolucionPagos => {
    const esSoloEfectivo = pagos.length === 1 && pagos[0].medio === 'EFECTIVO';

    const montoInvalido = pagos.some(
        (pago) =>
            pago.monto.trim() !== '' && normalizarMonto(pago.monto) === null,
    );

    const efectivoRecibido = esSoloEfectivo
        ? normalizarMonto(pagos[0].monto)
        : null;

    let pagado = 0;

    if (!esSoloEfectivo) {
        pagado = pagos.reduce(
            (acumulado, pago) => acumulado + (normalizarMonto(pago.monto) ?? 0),
            0,
        );
    } else if (efectivoRecibido !== null) {
        pagado = Math.min(efectivoRecibido, total);
    }

    const pagadoRedondeado = redondear(pagado);
    const totalRedondeado = redondear(total);

    const faltante = redondear(Math.max(0, total - pagado));
    const excedente = esSoloEfectivo
        ? 0
        : redondear(Math.max(0, pagado - total));

    const vuelto =
        esSoloEfectivo &&
        efectivoRecibido !== null &&
        efectivoRecibido >= total
            ? redondear(efectivoRecibido - total)
            : null;

    let error: string | null = null;

    if (montoInvalido) {
        error =
            'Ingresá un importe válido en cada pago (más de 0 y hasta 2 decimales).';
    } else if (!esSoloEfectivo && excedente > 0) {
        error = 'Los pagos superan el total de la venta.';
    }

    const puedeFinalizar = montoInvalido
        ? false
        : esSoloEfectivo
            ? efectivoRecibido !== null && efectivoRecibido >= total
            : pagadoRedondeado === totalRedondeado;

    const pagosEnviar: PagoEnvio[] = esSoloEfectivo
        ? [{ medio_pago: 'EFECTIVO', monto: redondear(total) }]
        : pagos.flatMap((pago) => {
              const monto = normalizarMonto(pago.monto);

              return monto === null
                  ? []
                  : [{ medio_pago: pago.medio, monto }];
          });

    return {
        esSoloEfectivo,
        efectivoRecibido,
        totalPagado: pagadoRedondeado,
        faltante,
        excedente,
        vuelto,
        montoInvalido,
        error,
        puedeFinalizar,
        pagosEnviar,
        efectivoRecibidoEnviar: esSoloEfectivo ? efectivoRecibido : null,
    };
};