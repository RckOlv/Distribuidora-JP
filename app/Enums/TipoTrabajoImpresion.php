<?php

namespace App\Enums;

enum TipoTrabajoImpresion: string
{
    case VENTA = 'VENTA';

    case REIMPRESION = 'REIMPRESION';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA => 'Original',
            self::REIMPRESION => 'Reimpresión',
        };
    }
}
