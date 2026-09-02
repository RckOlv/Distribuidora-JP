<?php

namespace App\Enums;

/**
 * Tipos de movimiento de caja.
 *
 * - VENTA: generado automáticamente por una venta. El cajero NO puede crearlo
 *   manualmente.
 * - INGRESO: dinero agregado manualmente a la caja (aporte, ajuste positivo).
 * - EGRESO: dinero retirado manualmente (gasto, ajuste negativo).
 */
enum TipoMovimientoCaja: string
{
    case VENTA = 'VENTA';

    case INGRESO = 'INGRESO';

    case EGRESO = 'EGRESO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA => 'Venta',
            self::INGRESO => 'Ingreso',
            self::EGRESO => 'Egreso',
        };
    }
}
