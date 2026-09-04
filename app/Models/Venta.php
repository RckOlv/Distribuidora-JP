<?php

namespace App\Models;

use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Venta extends Model
{
    protected $fillable = [
        'usuario_id',
        'caja_id',
        'medio_pago',
        'total',
        'efectivo_recibido',
        'vuelto',
        'estado_pago',
    ];

    protected function casts(): array
    {
        return [
            'medio_pago' => MedioPago::class,
            'estado_pago' => EstadoPagoVenta::class,
            'total' => 'decimal:2',
            'efectivo_recibido' => 'decimal:2',
            'vuelto' => 'decimal:2',
        ];
    }

    public function esPagada(): bool
    {
        return $this->estado_pago === EstadoPagoVenta::PAGADA;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }
}
