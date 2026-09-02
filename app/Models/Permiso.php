<?php

namespace App\Models;

use Database\Factories\PermisoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permiso extends Model
{
    /** @use HasFactory<PermisoFactory> */
    use HasFactory;

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'permiso_rol');
    }
}
