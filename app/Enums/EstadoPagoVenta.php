<?php

namespace App\Enums;

enum EstadoPagoVenta: string
{
    case PENDIENTE_PAGO = 'PENDIENTE_PAGO';
    case PAGADA = 'PAGADA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE_PAGO => 'Pendiente de pago',
            self::PAGADA => 'Pagada',
        };
    }
}
