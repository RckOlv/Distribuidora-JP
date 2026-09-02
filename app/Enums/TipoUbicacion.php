<?php

namespace App\Enums;

enum TipoUbicacion: string
{
    case LOCAL = 'LOCAL';
    case VEHICULO = 'VEHICULO';
    case OTRO = 'OTRO';
}
