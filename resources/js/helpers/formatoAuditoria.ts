import { formatoMoneda } from './formato';

export type TipoValorAuditoria =
    | 'texto'
    | 'moneda'
    | 'id'
    | 'fecha'
    | 'booleano'
    | 'nulo'
    | 'enumerado'
    | 'pagos';

export interface PagoAuditoria {
    etiqueta: string;
    monto: number;
}

export interface ValorAuditoria {
    texto: string;
    tipo: TipoValorAuditoria;
    booleano: boolean | null;
    pagos?: PagoAuditoria[];
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
    pagos: 'Pagos',
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

/** Texto simple para un valor escalar dentro de una estructura. */
const textoEscalar = (valor: unknown): string => {
    if (valor === null || valor === undefined) {
        return '—';
    }

    if (typeof valor === 'boolean') {
        return valor ? 'Sí' : 'No';
    }

    if (typeof valor === 'number') {
        return valor.toLocaleString('es-AR');
    }

    if (typeof valor === 'string') {
        return valor;
    }

    return JSON.stringify(valor);
};

const esMontoNumerico = (valor: unknown): valor is number | string =>
    (typeof valor === 'number' && Number.isFinite(valor)) ||
    (typeof valor === 'string' && /^-?\d+(\.\d+)?$/.test(valor));

const etiquetaDePago = (fila: Record<string, unknown>): string | null => {
    if (typeof fila.etiqueta === 'string' && fila.etiqueta !== '') {
        return fila.etiqueta;
    }

    if (typeof fila.medio_pago === 'string') {
        return valorEnumLegible(fila.medio_pago) ?? fila.medio_pago;
    }

    return null;
};

const esItemPago = (item: unknown): item is Record<string, unknown> => {
    if (item === null || typeof item !== 'object' || Array.isArray(item)) {
        return false;
    }

    const fila = item as Record<string, unknown>;

    const tieneNombre =
        (typeof fila.etiqueta === 'string' && fila.etiqueta !== '') ||
        (typeof fila.medio_pago === 'string' && fila.medio_pago !== '');

    return tieneNombre && esMontoNumerico(fila.monto);
};

/** Un array de objetos con etiqueta/medio_pago + monto es un desglose de pagos. */
const esListaPagos = (valor: unknown): valor is unknown[] =>
    Array.isArray(valor) && valor.length > 0 && valor.every(esItemPago);

const pagosDesde = (valor: unknown[]): PagoAuditoria[] =>
    valor.map((item) => {
        const fila = item as Record<string, unknown>;
        const etiqueta =
            typeof fila.etiqueta === 'string' && fila.etiqueta !== ''
                ? fila.etiqueta
                : etiquetaDePago(fila) ?? 'Pago';

        return { etiqueta, monto: Number(fila.monto) };
    });

export const totalPagos = (pagos: PagoAuditoria[]): number =>
    pagos.reduce((acumulado, pago) => acumulado + pago.monto, 0);

/** Texto de un valor anidado reutilizando las reglas de formatearValor. */
const textoDeValor = (clave: string, valor: unknown): string => {
    const formato = formatearValor(clave, valor);

    return formato.tipo === 'nulo' ? '—' : formato.texto;
};

/** Línea legible para un elemento de una lista estructurada (p. ej. un pago). */
const lineaDeElemento = (elemento: unknown): string => {
    if (
        elemento === null ||
        typeof elemento !== 'object' ||
        Array.isArray(elemento)
    ) {
        return textoEscalar(elemento);
    }

    const fila = elemento as Record<string, unknown>;

    // Desglose tipo pago: "Efectivo: $2.000,00".
    if (esMontoNumerico(fila.monto)) {
        const etiqueta = etiquetaDePago(fila);

        if (etiqueta !== null) {
            return `${etiqueta}: ${formatoMoneda(fila.monto)}`;
        }
    }

    return Object.entries(fila)
        .map(([clave, valor]) => `${etiquetaCampo(clave)}: ${textoDeValor(clave, valor)}`)
        .join(' — ');
};

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

    if (Array.isArray(valor)) {
        if (valor.length === 0) {
            return { texto: 'Sin valor', tipo: 'nulo', booleano: null };
        }

        if (esListaPagos(valor)) {
            const pagos = pagosDesde(valor);

            return {
                texto: formatoMoneda(totalPagos(pagos)),
                tipo: 'pagos',
                booleano: null,
                pagos,
            };
        }

        return {
            texto: valor.map(lineaDeElemento).join('\n'),
            tipo: 'texto',
            booleano: null,
        };
    }

    if (typeof valor === 'object') {
        const entradas = Object.entries(valor as Record<string, unknown>);

        if (entradas.length === 0) {
            return { texto: 'Sin valor', tipo: 'nulo', booleano: null };
        }

        return {
            texto: entradas
                .map(([clave, v]) => `${etiquetaCampo(clave)}: ${textoDeValor(clave, v)}`)
                .join('\n'),
            tipo: 'texto',
            booleano: null,
        };
    }

    return { texto: String(valor), tipo: 'texto', booleano: null };
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