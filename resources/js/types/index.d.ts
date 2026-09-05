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
    error: string | string[] | null;
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

export interface CostoVigente {
    id: number;
    precio: string;
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
    costo_vigente?: CostoVigente | null;
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

export interface PagoVenta {
    medio_pago: MedioPago;
    etiqueta: string;
    monto: number;
}

export interface VentaPendiente {
    id: number;
    numero: string;
    fecha: string | null;
    medio_pago: MedioPago;
    medio_pago_etiqueta: string;
    pagos: PagoVenta[];
    total: number;
    cantidad_items: number;
}

export type EstadoCaja = 'ABIERTA' | 'CERRADA';

export type TipoMovimientoCaja = 'VENTA' | 'INGRESO' | 'EGRESO';

export interface CajaFisica {
    id: number;
    nombre: string;
    activa: boolean;
    disponible: boolean;
}

export interface MovimientoCajaListado {
    id: number;
    tipo: 'INGRESO' | 'EGRESO';
    tipo_etiqueta: string;
    monto: number;
    medio_pago: MedioPago | null;
    medio_pago_etiqueta: string | null;
    concepto: string;
    fecha: string | null;
}

export interface ResumenCaja {
    id: number;
    estado: EstadoCaja;
    estado_etiqueta: string;
    caja_fisica_id: number | null;
    caja_fisica_nombre: string | null;
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
    movimientos: MovimientoCajaListado[];
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
    pagos: PagoVenta[];
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

export interface TrabajoImpresionDetalle {
    id: number;
    tipo: 'VENTA';
    tipo_etiqueta: string;
    estado: EstadoImpresion | null;
    estado_etiqueta: string | null;
    usuario: string | null;
    motivo: string | null;
    solicitado_en: string | null;
    impreso_en: string | null;
}

export interface VentaDetalle {
    id: number;
    numero: string;
    fecha: string | null;
    usuario: string | null;
    caja_id: number | null;
    medio_pago: MedioPago;
    medio_pago_etiqueta: string;
    pagos: PagoVenta[];
    efectivo_recibido: number | null;
    vuelto: number | null;
    total: number;
    ticket_numero: string | null;
    ticket: {
        numero: string;
        estado_impresion: EstadoImpresion | null;
        estado_impresion_etiqueta: string | null;
        contenido: TicketContenido | null;
    } | null;
}

export interface TicketComercio {
    nombre: string;
    direccion: string | null;
    telefono: string | null;
    leyenda: string | null;
}

export interface TicketDetalle {
    nombre: string;
    unidad_medida: string;
    cantidad: string;
    precio_unitario: string;
    subtotal: string;
}

export interface TicketPago {
    medio_pago: string;
    etiqueta: string;
    monto: string;
}

export interface TicketContenido {
    numero: string;
    fecha: string;
    usuario: string;
    medio_pago: string;
    pagos?: TicketPago[];
    total: string;
    comercio: TicketComercio;
    detalles: TicketDetalle[];
}

export interface UsuarioOpcion {
    id: number;
    name: string;
}

export type AccionAuditoria =
    | 'USUARIO_CREADO'
    | 'USUARIO_MODIFICADO'
    | 'USUARIO_ACTIVADO'
    | 'USUARIO_DESACTIVADO'
    | 'PRODUCTO_CREADO'
    | 'PRODUCTO_MODIFICADO'
    | 'PRODUCTO_ACTIVADO'
    | 'PRODUCTO_DESACTIVADO'
    | 'CATEGORIA_CREADA'
    | 'CATEGORIA_MODIFICADA'
    | 'CATEGORIA_ACTIVADA'
    | 'CATEGORIA_DESACTIVADA'
    | 'PRECIO_MODIFICADO'
    | 'CAJA_ABIERTA'
    | 'CAJA_CERRADA'
    | 'INGRESO_CAJA'
    | 'EGRESO_CAJA'
    | 'VENTA_REALIZADA';

export interface OpcionAccionAuditoria {
    valor: AccionAuditoria;
    etiqueta: string;
}

export interface OpcionEntidadAuditoria {
    valor: string;
    etiqueta: string;
}

export interface RegistroAuditoria {
    id: number;
    fecha: string | null;
    usuario: string | null;
    accion: AccionAuditoria;
    accion_etiqueta: string;
    entidad_tipo: string;
    entidad_id: number | null;
    descripcion: string | null;
}

export interface FiltrosAuditoria {
    fecha_desde: string | null;
    fecha_hasta: string | null;
    usuario_id: number | null;
    accion: string;
    entidad_tipo: string;
}

export interface RegistroAuditoriaDetalle {
    id: number;
    fecha: string | null;
    usuario: string | null;
    accion: AccionAuditoria;
    accion_etiqueta: string;
    entidad_tipo: string;
    entidad_id: number | null;
    descripcion: string | null;
    datos_anteriores: Record<string, unknown> | null;
    datos_nuevos: Record<string, unknown> | null;
}

export interface DesgloseMedio {
    medio: MedioPago;
    etiqueta: string;
    cantidad: number;
    total: number;
}

export interface ResumenVentasDiario {
    cantidad: number;
    total: number;
    por_medio: DesgloseMedio[];
}

export interface ResumenGananciaDiario {
    total: number;
    con_costo: number;
    sin_costo: number;
    costo: number;
    ganancia: number | null;
    margen: number | null;
}

export interface CajaReporte {
    id: number;
    estado: string;
    estado_etiqueta: string;
    usuario_abrio: string | null;
    abierta_en: string | null;
    cerrada_en: string | null;
    monto_inicial: number;
    total_ventas: number;
    cantidad_ventas: number;
    ventas_efectivo: number;
    ventas_tarjeta: number;
    ventas_transferencia: number;
    otros: number;
    ingresos: number;
    egresos: number;
    efectivo_esperado: number;
}

export interface AgregadoCajas {
    cantidad_sesiones: number;
    monto_inicial: number;
    total_ventas: number;
    cantidad_ventas: number;
    ventas_efectivo: number;
    ingresos: number;
    egresos: number;
    efectivo_esperado: number;
}

export interface CajasReporte {
    cajas: CajaReporte[];
    agregado: AgregadoCajas;
}

export interface ProductoMasVendido {
    producto_id: number;
    nombre: string;
    cantidad: number;
    total: number;
    costo: number;
}

export interface VentasPorDia {
    fecha: string;
    total: number;
}

export interface ResumenDiario {
    fecha: string;
    desde: string;
    hasta: string;
    ventas: ResumenVentasDiario;
    ganancia: ResumenGananciaDiario;
    cajas: CajasReporte;
    mas_vendidos: ProductoMasVendido[];
    ventas_por_dia: VentasPorDia[];
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