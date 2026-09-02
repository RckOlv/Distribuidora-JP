<?php

namespace App\Support;

/**
 * Catálogo de permisos del sistema.
 *
 * Los nombres se agrupan por módulo con la sintaxis modulo.accion.
 * Para agregar un permiso nuevo: agregarlo aquí y crearlo en el seeder.
 */
final class Permisos
{
    // Productos
    public const PRODUCTOS_VER = 'productos.ver';

    public const PRODUCTOS_CREAR = 'productos.crear';

    public const PRODUCTOS_EDITAR = 'productos.editar';

    public const PRODUCTOS_ELIMINAR = 'productos.eliminar';

    // Categorías
    public const CATEGORIAS_VER = 'categorias.ver';

    public const CATEGORIAS_GESTIONAR = 'categorias.gestionar';

    // Precios
    public const PRECIOS_GESTIONAR = 'precios.gestionar';

    // Stock y ubicaciones
    public const STOCK_VER = 'stock.ver';

    public const STOCK_AJUSTAR = 'stock.ajustar';

    public const UBICACIONES_GESTIONAR = 'ubicaciones.gestionar';

    // Ventas
    public const VENTAS_REALIZAR = 'ventas.realizar';

    public const VENTAS_VER = 'ventas.ver';

    public const TICKETS_IMPRIMIR = 'tickets.imprimir';

    // Cola de impresión (futuro print bridge)
    public const IMPRESIONES_GESTIONAR = 'impresiones.gestionar';

    // Punto de venta
    public const POS_USAR = 'pos.usar';

    // Caja
    public const CAJAS_USAR = 'cajas.usar';

    public const CAJAS_VER = 'cajas.ver';

    // Administración
    public const USUARIOS_GESTIONAR = 'usuarios.gestionar';

    public const REPORTES_VER = 'reportes.ver';

    public const CONFIGURACION_GESTIONAR = 'configuracion.gestionar';

    /**
     * @return list<string>
     */
    public static function todos(): array
    {
        $constantes = (new \ReflectionClass(self::class))->getConstants();

        return array_values($constantes);
    }
}
