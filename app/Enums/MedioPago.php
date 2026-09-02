<?php

namespace App\Enums;

enum MedioPago: string
{
    case EFECTIVO = 'EFECTIVO';
    case TARJETA = 'TARJETA';
    case TRANSFERENCIA = 'TRANSFERENCIA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EFECTIVO => 'Efectivo',
            self::TARJETA => 'Tarjeta',
            self::TRANSFERENCIA => 'Transferencia',
        };
    }
}
