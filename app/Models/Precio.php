<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Precio extends Model
{
    protected $fillable = [
        'producto_id',
        'usuario_id',
        'monto',
        'vigente',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
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
