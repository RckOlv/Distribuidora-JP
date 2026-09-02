export interface Usuario {
    id: number;
    name: string;
    email: string;
    rol: string | null;
    es_dueno: boolean;
}

export type NombreRol = 'DUENO' | 'CAJERO';

export interface RolOpcion {
    id: number;
    nombre: NombreRol;
}

export interface UsuarioGestion {
    id: number;
    name: string;
    email: string;
    rol: { id: number; nombre: NombreRol } | null;
    activo: boolean;
    created_at: string | null;
}

export interface AuthCompartido {
    user: Usuario | null;
    permisos: string[];
}

export interface FlashCompartido {
    success: string | null;
}

export interface TotalesDashboard {
    productos: number | null;
    categorias: number | null;
}

export type UnidadVenta = 'KILOGRAMO' | 'BOLSA' | 'UNIDAD';

export interface Categoria {
    id: number;
    nombre: string;
    descripcion: string | null;
    activa: boolean;
    productos_count?: number;
}

export interface PrecioVigente {
    id: number;
    monto: string;
}

export interface Producto {
    id: number;
    categoria_id: number;
    categoria?: { id: number; nombre: string } | null;
    codigo: string | null;
    nombre: string;
    descripcion: string | null;
    imagen: string | null;
    imagen_url: string | null;
    unidad_medida: UnidadVenta;
    activo: boolean;
    precio_vigente: PrecioVigente | null;
}

export interface Paginacion {
    data: Producto[];
    current_page: number;
    last_page: number;
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
}

export interface Paginador<T> {
    data: T[];
    current_page: number;
    last_page: number;
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
}

export interface FiltrosProductos {
    q: string;
    categoria_id: number | null;
    estado: 'todos' | 'activos' | 'inactivos';
}

export type MedioPago = 'EFECTIVO' | 'TARJETA' | 'TRANSFERENCIA';

export interface CategoriaVenta {
    id: number;
    nombre: string;
}

export interface ProductoVenta {
    id: number;
    nombre: string;
    codigo: string | null;
    unidad_medida: UnidadVenta;
    categoria_id: number;
    categoria_nombre: string;
    imagen_url: string | null;
    precio: string | null;
}

export interface OpcionMedioPago {
    valor: MedioPago;
    etiqueta: string;
}

export type EstadoCaja = 'ABIERTA' | 'CERRADA';

export type TipoMovimientoCaja = 'VENTA' | 'INGRESO' | 'EGRESO';

export interface ResumenCaja {
    id: number;
    estado: EstadoCaja;
    estado_etiqueta: string;
    usuario_abrio: string | null;
    abierta_en: string | null;
    cerrada_en: string | null;
    monto_inicial: number;
    total_ventas: number;
    cantidad_ventas: number;
    ventas_efectivo: number;
    ventas_transferencia: number;
    ventas_tarjeta: number;
    otros: number;
    ingresos: number;
    egresos: number;
    efectivo_esperado: number;
    efectivo_contado: number | null;
    diferencia: number | null;
}

export interface ItemCarrito {
    producto: ProductoVenta;
    cantidad: number;
}

export type EstadoImpresion = 'PENDIENTE' | 'PROCESANDO' | 'IMPRESO' | 'ERROR';

export interface VentaHistorial {
    id: number;
    numero: string;
    fecha: string | null;
    usuario: string | null;
    medio_pago: MedioPago;
    medio_pago_etiqueta: string;
    caja_id: number | null;
    total: number;
    estado_impresion: EstadoImpresion | null;
    estado_impresion_etiqueta: string | null;
    puede_ver: boolean;
}

export interface FiltrosVentas {
    fecha_desde: string | null;
    fecha_hasta: string | null;
    numero: string;
    usuario_id: number | null;
    medio_pago: string;
}

export interface DetalleVentaHistorial {
    producto_id: number;
    nombre: string;
    unidad_medida: UnidadVenta | null;
    cantidad: number;
    precio_unitario: number;
    subtotal: number;
}

export interface VentaDetalle {
    id: number;
    numero: string;
    fecha: string | null;
    usuario: string | null;
    caja_id: number | null;
    medio_pago: MedioPago;
    medio_pago_etiqueta: string;
    total: number;
    ticket_numero: string | null;
    ticket: {
        numero: string;
        estado_impresion: EstadoImpresion | null;
        estado_impresion_etiqueta: string | null;
    } | null;
}

export interface UsuarioOpcion {
    id: number;
    name: string;
}

declare module '@inertiajs/core' {
    interface PageProps {
        auth: AuthCompartido;
        flash: FlashCompartido;
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}