<?php

namespace App\Enums;

/**
 * Estados de la cola de impresión.
 *
 * - PENDIENTE: el trabajo espera ser tomado por un print bridge.
 * - PROCESANDO: un bridge lo tomó (claim atómico) y está imprimiendo.
 * - IMPRESO: impresión confirmada por el bridge.
 * - ERROR: el bridge informó un fallo (se reutiliza en reintentos posteriores).
 */
enum EstadoImpresion: string
{
    case PENDIENTE = 'PENDIENTE';

    case PROCESANDO = 'PROCESANDO';

    case IMPRESO = 'IMPRESO';

    case ERROR = 'ERROR';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::PROCESANDO => 'Procesando',
            self::IMPRESO => 'Impreso',
            self::ERROR => 'Error',
        };
    }
}
