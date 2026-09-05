<?php

namespace App\Models;

use App\Enums\MedioPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoVenta extends Model
{
    protected $table = 'pagos_venta';

    protected $fillable = [
        'venta_id',
        'medio_pago',
        'monto',
    ];

    protected function casts(): array
    {
        return [
            'medio_pago' => MedioPago::class,
            'monto' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
