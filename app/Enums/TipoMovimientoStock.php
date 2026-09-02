<?php

namespace App\Enums;

enum TipoMovimientoStock: string
{
    case ENTRADA = 'ENTRADA';
    case SALIDA = 'SALIDA';
    case TRANSFERENCIA = 'TRANSFERENCIA';
}
