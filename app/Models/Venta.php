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

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }

    /**
     * Desglose de pagos como arreglo normalizado. Las ventas históricas
     * (anteriores a la tabla pagos_venta) no tienen filas y caen al medio
     * original con el total como monto.
     */
    public function pagosNormalizados(): array
    {
        $pagos = $this->pagos()
            ->orderBy('id')
            ->get()
            ->map(fn (PagoVenta $pago): array => [
                'medio_pago' => $pago->medio_pago->value,
                'etiqueta' => $pago->medio_pago->etiqueta(),
                'monto' => $pago->monto,
            ])
            ->values()
            ->all();

        if ($pagos !== []) {
            return $pagos;
        }

        return [[
            'medio_pago' => $this->medio_pago->value,
            'etiqueta' => $this->medio_pago->etiqueta(),
            'monto' => $this->total,
        ]];
    }
}
