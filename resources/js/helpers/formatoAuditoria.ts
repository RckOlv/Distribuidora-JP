import { formatoMoneda } from './formato';

export type TipoValorAuditoria =
    | 'texto'
    | 'moneda'
    | 'id'
    | 'fecha'
    | 'booleano'
    | 'nulo'
    | 'enumerado';

export interface ValorAuditoria {
    texto: string;
    tipo: TipoValorAuditoria;
    booleano: boolean | null;
}

export interface CampoAuditoria {
    clave: string;
    etiqueta: string;
    valor: ValorAuditoria;
}

export interface CambioAuditoria {
    clave: string;
    etiqueta: string;
    anterior: ValorAuditoria;
    nuevo: ValorAuditoria;
}

const ETIQUETAS: Record<string, string> = {
    name: 'Nombre',
    email: 'Email',
    rol: 'Rol',
    activo: 'Activo',
    password_cambiada: 'Contraseña cambiada',
    nombre: 'Nombre',
    categoria_id: 'Categoría',
    unidad_medida: 'Unidad de medida',
    codigo: 'Código',
    descripcion: 'Descripción',
    imagen: 'Imagen',
    activa: 'Activa',
    producto_id: 'Producto',
    producto: 'Producto',
    precio_nuevo: 'Precio de venta',
    precio_anterior: 'Precio de venta',
    precio: 'Precio de venta',
    costo_nuevo: 'Costo',
    costo_anterior: 'Costo',
    costo: 'Costo',
    vigente: 'Vigente',
    caja_id: 'Caja',
    caja_fisica_id: 'Caja física',
    caja_fisica_nombre: 'Caja física',
    usuario_abre_id: 'Usuario que abrió',
    monto_inicial: 'Monto inicial',
    abierta_en: 'Abierta en',
    movimiento_id: 'Movimiento',
    tipo: 'Tipo',
    monto: 'Monto',
    concepto: 'Concepto',
    efectivo_esperado: 'Efectivo esperado',
    efectivo_contado: 'Efectivo contado',
    diferencia: 'Diferencia',
    observacion_cierre: 'Observación',
    cerrada_en: 'Cerrada en',
    estado_anterior: 'Estado',
    venta_id: 'Venta',
    usuario_id: 'Usuario',
    numero: 'Número',
    medio_pago: 'Medio de pago',
    total: 'Total',
};

const ETIQUETAS_VALOR: Record<string, string> = {
    EFECTIVO: 'Efectivo',
    TARJETA: 'Tarjeta',
    TRANSFERENCIA: 'Transferencia',
    INGRESO: 'Ingreso',
    EGRESO: 'Egreso',
    VENTA: 'Venta',
    ABIERTA: 'Abierta',
    CERRADA: 'Cerrada',
    KILOGRAMO: 'Kilogramo',
    BOLSA: 'Bolsa',
    UNIDAD: 'Unidad',
    DUENO: 'Dueño',
    CAJERO: 'Cajero',
    PAGADA: 'Pagada',
    PENDIENTE: 'Pendiente',
};

const CLAVES_MONEDA = /(?:monto|precio|costo|total|efectivo|diferencia|ganancia)/i;
const CLAVE_ID = /_id$/;
const ES_FECHA_ISO = /^\d{4}-\d{2}-\d{2}T/;
const ES_ENUM = /^[A-Z][A-Z0-9_]+$/;

const capitalizar = (texto: string): string =>
    texto.length === 0
        ? texto
        : texto.charAt(0).toUpperCase() + texto.slice(1);

const desSnakeCase = (clave: string): string => clave.replace(/_/g, ' ');

export const etiquetaCampo = (clave: string): string =>
    ETIQUETAS[clave] ?? capitalizar(desSnakeCase(clave));

export const valorEnumLegible = (valor: string): string | null => {
    if (ETIQUETAS_VALOR[valor] !== undefined) {
        return ETIQUETAS_VALOR[valor];
    }

    if (ES_ENUM.test(valor)) {
        return capitalizar(desSnakeCase(valor.toLowerCase()));
    }

    return null;
};

const formatoId = (clave: string, valor: number): string =>
    clave === 'caja_fisica_id' ? `Caja ${valor}` : `#${valor}`;

export const esNulo = (valor: unknown): boolean =>
    valor === null || valor === undefined || valor === '';

export const formatearValor = (clave: string, valor: unknown): ValorAuditoria => {
    if (esNulo(valor)) {
        return { texto: 'Sin valor', tipo: 'nulo', booleano: null };
    }

    if (typeof valor === 'boolean') {
        return {
            texto: valor ? 'Sí' : 'No',
            tipo: 'booleano',
            booleano: valor,
        };
    }

    if (typeof valor === 'number') {
        if (CLAVE_ID.test(clave)) {
            return { texto: formatoId(clave, valor), tipo: 'id', booleano: null };
        }

        if (CLAVES_MONEDA.test(clave)) {
            return { texto: formatoMoneda(valor), tipo: 'moneda', booleano: null };
        }

        return {
            texto: valor.toLocaleString('es-AR'),
            tipo: 'texto',
            booleano: null,
        };
    }

    if (typeof valor === 'string') {
        if (ES_FECHA_ISO.test(valor)) {
            const fecha = new Date(valor);

            if (!Number.isNaN(fecha.getTime())) {
                return {
                    texto: fecha.toLocaleString('es-AR'),
                    tipo: 'fecha',
                    booleano: null,
                };
            }
        }

        if (CLAVE_ID.test(clave) && /^\d+$/.test(valor)) {
            return { texto: formatoId(clave, Number(valor)), tipo: 'id', booleano: null };
        }

        if (CLAVES_MONEDA.test(clave) && /^-?\d+(\.\d+)?$/.test(valor)) {
            return { texto: formatoMoneda(Number(valor)), tipo: 'moneda', booleano: null };
        }

        const enumerado = valorEnumLegible(valor);

        if (enumerado !== null) {
            return { texto: enumerado, tipo: 'enumerado', booleano: null };
        }

        return { texto: valor, tipo: 'texto', booleano: null };
    }

    return { texto: JSON.stringify(valor, null, 2), tipo: 'texto', booleano: null };
};

export const camposDe = (datos: Record<string, unknown> | null): CampoAuditoria[] => {
    if (!datos) {
        return [];
    }

    return Object.entries(datos).map(([clave, valor]) => ({
        clave,
        etiqueta: etiquetaCampo(clave),
        valor: formatearValor(clave, valor),
    }));
};

const normalizarClave = (clave: string): string =>
    clave
        .replace(/_(anterior|previo)$/, '')
        .replace(/_nuevo$/, '');

export const cambiosDe = (
    anteriores: Record<string, unknown> | null,
    nuevos: Record<string, unknown> | null,
): CambioAuditoria[] => {
    if (!anteriores && !nuevos) {
        return [];
    }

    const filas: Record<string, { anterior: unknown; nuevo: unknown }> = {};

    Object.entries(anteriores ?? {}).forEach(([clave, valor]) => {
        const base = normalizarClave(clave);
        filas[base] = { ...(filas[base] ?? { anterior: undefined, nuevo: undefined }), anterior: valor };
    });

    Object.entries(nuevos ?? {}).forEach(([clave, valor]) => {
        const base = normalizarClave(clave);
        filas[base] = { ...(filas[base] ?? { anterior: undefined, nuevo: undefined }), nuevo: valor };
    });

    return Object.entries(filas).map(([clave, fila]) => ({
        clave,
        etiqueta: etiquetaCampo(clave),
        anterior: formatearValor(clave, fila.anterior),
        nuevo: formatearValor(clave, fila.nuevo),
    }));
};