<?php

namespace App\Models;

use App\Enums\EstadoCaja;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesión de caja.
 *
 * Representa una caja abierta por un usuario hasta su cierre. Guarda el
 * monto inicial, fechas y (al cerrar) el resultado histórico contado/esperado.
 */
class Caja extends Model
{
    protected $fillable = [
        'caja_fisica_id',
        'usuario_abre_id',
        'estado',
        'monto_inicial',
        'abierta_en',
        'cerrada_en',
        'efectivo_contado',
        'efectivo_esperado',
        'diferencia',
        'observacion_cierre',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCaja::class,
            'monto_inicial' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    public function usuarioAbrio(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_abre_id');
    }

    public function cajaFisica(): BelongsTo
    {
        return $this->belongsTo(CajaFisica::class, 'caja_fisica_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function scopeAbierta(Builder $query): Builder
    {
        return $query->where('estado', EstadoCaja::ABIERTA->value);
    }

    public function scopeCerrada(Builder $query): Builder
    {
        return $query->where('estado', EstadoCaja::CERRADA->value);
    }

    public function esAbierta(): bool
    {
        return $this->estado === EstadoCaja::ABIERTA;
    }
}
