export const ZONA_ARGENTINA = 'America/Argentina/Buenos_Aires';

export interface RangoFechas {
    desde: string;
    hasta: string;
}

/**
 * Fecha actual (calendar) en la timezone de la app, como YYYY-MM-DD.
 */
const fechaHoyEnZona = (ahora: Date): string =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: ZONA_ARGENTINA,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(ahora);

/**
 * Trata el string YYYY-MM-DD como un día en UTC para poder hacer aritmética
 * calendar sin depender de la timezone local del navegador.
 */
const aUtc = (iso: string): Date => {
    const [anio, mes, dia] = iso.split('-').map(Number);

    return new Date(Date.UTC(anio, mes - 1, dia));
};

const aIso = (fecha: Date): string => {
    const anio = fecha.getUTCFullYear();
    const mes = String(fecha.getUTCMonth() + 1).padStart(2, '0');
    const dia = String(fecha.getUTCDate()).padStart(2, '0');

    return `${anio}-${mes}-${dia}`;
};

export const rangoHoy = (ahora: Date = new Date()): RangoFechas => {
    const hoy = fechaHoyEnZona(ahora);

    return { desde: hoy, hasta: hoy };
};

/** Lunes a domingo de la semana actual, respetando la timezone de la app. */
export const rangoSemanaActual = (ahora: Date = new Date()): RangoFechas => {
    const hoy = aUtc(fechaHoyEnZona(ahora));
    const diaSemana = hoy.getUTCDay();
    const diasDesdeLunes = (diaSemana + 6) % 7;

    const lunes = new Date(hoy);
    lunes.setUTCDate(hoy.getUTCDate() - diasDesdeLunes);

    const domingo = new Date(lunes);
    domingo.setUTCDate(lunes.getUTCDate() + 6);

    return { desde: aIso(lunes), hasta: aIso(domingo) };
};

/** Primer y último día del mes actual, respetando la timezone de la app. */
export const rangoMesActual = (ahora: Date = new Date()): RangoFechas => {
    const hoy = aUtc(fechaHoyEnZona(ahora));
    const primero = new Date(Date.UTC(hoy.getUTCFullYear(), hoy.getUTCMonth(), 1));
    const ultimo = new Date(Date.UTC(hoy.getUTCFullYear(), hoy.getUTCMonth() + 1, 0));

    return { desde: aIso(primero), hasta: aIso(ultimo) };
};

/** Formatea un YYYY-MM-DD a DD/MM/YYYY sin depender de la timezone del navegador. */
export const formatoFechaLegible = (iso: string | null | undefined): string => {
    if (!iso) {
        return '—';
    }

    const [anio, mes, dia] = iso.split('-');

    if (!anio || !mes || !dia) {
        return iso;
    }

    return `${dia}/${mes}/${anio}`;
};