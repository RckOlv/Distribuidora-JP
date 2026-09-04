<?php

namespace App\Enums;

/**
 * Acciones de auditoría registradas en el sistema.
 *
 * Centraliza los identificadores de acción para evitar strings arbitrarios
 * repartidos por el código. Solo incluye acciones realmente auditadas.
 */
enum AccionAuditoria: string
{
    // Usuarios
    case USUARIO_CREADO = 'USUARIO_CREADO';

    case USUARIO_MODIFICADO = 'USUARIO_MODIFICADO';

    case USUARIO_ACTIVADO = 'USUARIO_ACTIVADO';

    case USUARIO_DESACTIVADO = 'USUARIO_DESACTIVADO';

    // Productos
    case PRODUCTO_CREADO = 'PRODUCTO_CREADO';

    case PRODUCTO_MODIFICADO = 'PRODUCTO_MODIFICADO';

    case PRODUCTO_ACTIVADO = 'PRODUCTO_ACTIVADO';

    case PRODUCTO_DESACTIVADO = 'PRODUCTO_DESACTIVADO';

    // Categorías
    case CATEGORIA_CREADA = 'CATEGORIA_CREADA';

    case CATEGORIA_MODIFICADA = 'CATEGORIA_MODIFICADA';

    case CATEGORIA_ACTIVADA = 'CATEGORIA_ACTIVADA';

    case CATEGORIA_DESACTIVADA = 'CATEGORIA_DESACTIVADA';

    // Precios
    case PRECIO_CREADO = 'PRECIO_CREADO';

    case PRECIO_MODIFICADO = 'PRECIO_MODIFICADO';

    // Costos
    case COSTO_CREADO = 'COSTO_CREADO';

    case COSTO_MODIFICADO = 'COSTO_MODIFICADO';

    // Caja
    case CAJA_ABIERTA = 'CAJA_ABIERTA';

    case CAJA_CERRADA = 'CAJA_CERRADA';

    case INGRESO_CAJA = 'INGRESO_CAJA';

    case EGRESO_CAJA = 'EGRESO_CAJA';

    // Ventas
    case VENTA_REALIZADA = 'VENTA_REALIZADA';

    // Ticket reimpreso
    case TICKET_REIMPRESO = 'TICKET_REIMPRESO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::USUARIO_CREADO => 'Usuario creado',
            self::USUARIO_MODIFICADO => 'Usuario modificado',
            self::USUARIO_ACTIVADO => 'Usuario activado',
            self::USUARIO_DESACTIVADO => 'Usuario desactivado',
            self::PRODUCTO_CREADO => 'Producto creado',
            self::PRODUCTO_MODIFICADO => 'Producto modificado',
            self::PRODUCTO_ACTIVADO => 'Producto activado',
            self::PRODUCTO_DESACTIVADO => 'Producto desactivado',
            self::CATEGORIA_CREADA => 'Categoría creada',
            self::CATEGORIA_MODIFICADA => 'Categoría modificada',
            self::CATEGORIA_ACTIVADA => 'Categoría activada',
            self::CATEGORIA_DESACTIVADA => 'Categoría desactivada',
            self::PRECIO_CREADO => 'Precio creado',
            self::PRECIO_MODIFICADO => 'Precio modificado',
            self::COSTO_CREADO => 'Costo creado',
            self::COSTO_MODIFICADO => 'Costo modificado',
            self::CAJA_ABIERTA => 'Caja abierta',
            self::CAJA_CERRADA => 'Caja cerrada',
            self::INGRESO_CAJA => 'Ingreso de caja',
            self::EGRESO_CAJA => 'Egreso de caja',
            self::VENTA_REALIZADA => 'Venta realizada',
            self::TICKET_REIMPRESO => 'Ticket reimpreso',
        };
    }
}
