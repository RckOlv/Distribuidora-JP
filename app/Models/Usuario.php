<?php

namespace App\Models;

use App\Support\Permisos;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol_id',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function esDueno(): bool
    {
        return $this->rol?->nombre === Rol::DUENO;
    }

    public function tieneRol(string $nombre): bool
    {
        return $this->rol?->nombre === $nombre;
    }

    public function tienePermiso(string $nombre): bool
    {
        if ($this->esDueno()) {
            return true;
        }

        if (! $this->relationLoaded('rol')) {
            $this->load('rol.permisos');
        }

        return $this->rol->tienePermiso($nombre);
    }

    /**
     * Permisos efectivos del usuario.
     *
     * @return list<string>
     */
    public function permisos(): array
    {
        if ($this->esDueno()) {
            return Permisos::todos();
        }

        $this->loadMissing('rol.permisos');

        return $this->rol->permisos->pluck('nombre')->values()->all();
    }
}
