<?php

namespace App\Models;

use App\Enums\TipoMovimientoStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MovimientoStock extends Model
{
    protected $table = 'movimientos_stock';

    protected $fillable = [
        'producto_id',
        'tipo',
        'cantidad',
        'origen_ubicacion_id',
        'destino_ubicacion_id',
        'usuario_id',
        'movible_type',
        'movible_id',
        'descripcion',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoStock::class,
            'cantidad' => 'decimal:3',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'origen_ubicacion_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'destino_ubicacion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function movible(): MorphTo
    {
        return $this->morphTo();
    }
}
