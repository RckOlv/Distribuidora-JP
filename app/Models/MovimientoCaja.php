<?php

namespace App\Models;

use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    protected $fillable = [
        'caja_id',
        'venta_id',
        'usuario_id',
        'tipo',
        'monto',
        'medio_pago',
        'concepto',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoCaja::class,
            'medio_pago' => MedioPago::class,
            'monto' => 'decimal:2',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
