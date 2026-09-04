<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caja física (puesto permanente del negocio).
 *
 * A diferencia de {@see Caja} (que es una sesión/apertura), una caja física
 * representa el terminal/puesto físico que puede abrirse y cerrarse en
 * distintos momentos. Cada caja física puede tener como máximo una sesión
 * ABIERTA simultáneamente.
 */
class CajaFisica extends Model
{
    use HasFactory;

    protected $table = 'cajas_fisicas';

    protected $fillable = [
        'nombre',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * Sesiones/aperturas históricas de esta caja física.
     */
    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class, 'caja_fisica_id');
    }
}
