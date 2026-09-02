<?php

namespace App\Models;

use App\Enums\EstadoImpresion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrabajoImpresion extends Model
{
    protected $table = 'trabajos_impresion';

    protected $fillable = [
        'ticket_id',
        'dispositivo_id',
        'estado',
        'cantidad_intentos',
        'procesando_at',
        'ultimo_error',
        'impreso_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoImpresion::class,
            'cantidad_intentos' => 'integer',
            'procesando_at' => 'datetime',
            'impreso_en' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoImpresion::class);
    }
}
