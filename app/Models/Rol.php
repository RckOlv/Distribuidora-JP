<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    public const DUENO = 'DUENO';

    public const CAJERO = 'CAJERO';

    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'permiso_rol');
    }

    public function tienePermiso(string $nombre): bool
    {
        return $this->permisos->contains('nombre', $nombre);
    }
}
