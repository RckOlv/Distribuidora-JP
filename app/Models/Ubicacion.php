<?php

namespace App\Models;

use App\Enums\TipoUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ubicacion extends Model
{
    protected $table = 'ubicaciones';

    protected $fillable = [
        'nombre',
        'tipo',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoUbicacion::class,
            'activa' => 'boolean',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
