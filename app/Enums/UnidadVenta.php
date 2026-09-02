<?php

namespace App\Enums;

enum UnidadVenta: string
{
    case KILOGRAMO = 'KILOGRAMO';
    case UNIDAD = 'UNIDAD';
    case BOLSA = 'BOLSA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::KILOGRAMO => 'Kilogramo',
            self::UNIDAD => 'Unidad',
            self::BOLSA => 'Bolsa',
        };
    }
}
