<?php

namespace App\Enums;

/**
 * Estados de una caja (sesión).
 *
 * - ABIERTA: la caja está operativa; se pueden registrar ventas y movimientos.
 * - CERRADA: la caja quedó cerrada; no admite nuevas operaciones.
 */
enum EstadoCaja: string
{
    case ABIERTA = 'ABIERTA';

    case CERRADA = 'CERRADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ABIERTA => 'Abierta',
            self::CERRADA => 'Cerrada',
        };
    }
}
