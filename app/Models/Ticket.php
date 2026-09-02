<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function trabajoImpresion(): HasOne
    {
        return $this->hasOne(TrabajoImpresion::class);
    }
}
