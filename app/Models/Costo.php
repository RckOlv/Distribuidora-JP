<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Costo extends Model
{
    protected $fillable = [
        'producto_id',
        'usuario_id',
        'precio',
        'vigente',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'vigente' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
