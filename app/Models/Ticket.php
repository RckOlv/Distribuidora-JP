<?php

namespace App\Models;

use App\Enums\TipoTrabajoImpresion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    protected $fillable = [
        'venta_id',
        'numero',
        'contenido',
        'impreso_en',
    ];

    protected function casts(): array
    {
        return [
            'impreso_en' => 'datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * Trabajo de impresión original (tipo VENTA).
     */
    public function trabajoImpresion(): HasOne
    {
        return $this->hasOne(TrabajoImpresion::class)
            ->where('tipo', TipoTrabajoImpresion::VENTA->value);
    }

    /**
     * Todos los trabajos de impresión del ticket (incluye históricos).
     */
    public function trabajos(): HasMany
    {
        return $this->hasMany(TrabajoImpresion::class)
            ->orderBy('id');
    }
}
