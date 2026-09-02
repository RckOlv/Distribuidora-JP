import type { UnidadVenta } from '@/types';

export const formatoMoneda = (
    monto: string | number | null | undefined,
): string => {
    if (monto === null || monto === undefined || monto === '') {
        return '—';
    }

    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
    }).format(Number(monto));
};

export const etiquetaUnidad = (unidad: UnidadVenta): string => {
    switch (unidad) {
        case 'KILOGRAMO':
            return 'Kilogramo';
        case 'BOLSA':
            return 'Bolsa';
        case 'UNIDAD':
            return 'Unidad';
    }
};

export const unidadDescripcion = (unidad: UnidadVenta): string => {
    switch (unidad) {
        case 'KILOGRAMO':
            return 'Se vende por peso: admite cantidades decimales (p. ej. 1,5 kg).';
        case 'BOLSA':
            return 'Se vende por bolsa: solo cantidades enteras.';
        case 'UNIDAD':
            return 'Se vende por unidad: solo cantidades enteras.';
    }
};

export const opcionesUnidad = (): { valor: UnidadVenta; etiqueta: string }[] => [
    { valor: 'KILOGRAMO', etiqueta: etiquetaUnidad('KILOGRAMO') },
    { valor: 'BOLSA', etiqueta: etiquetaUnidad('BOLSA') },
    { valor: 'UNIDAD', etiqueta: etiquetaUnidad('UNIDAD') },
];