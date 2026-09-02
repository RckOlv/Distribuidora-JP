<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Print bridge físico con identidad propia de dispositivo.
 *
 * El token real vive solo en el bridge; en la base se guarda un hash seguro.
 */
class DispositivoImpresion extends Model
{
    protected $table = 'dispositivos_impresion';

    protected $fillable = [
        'nombre',
        'token_hash',
        'activo',
        'ultima_conexion',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultima_conexion' => 'datetime',
        ];
    }

    public function verificarToken(string $token): bool
    {
        return $this->activo && Hash::check($token, $this->token_hash);
    }

    /**
     * Registra la última conexión sin hacer un UPDATE en cada polling.
     *
     * El bridge consulta cada ~3s; escribir `ultima_conexion` en cada request
     * generaría cientos/miles de UPDATE innecesarios. Se limita a una escritura
     * como máximo cada `ultima_conexion_intervalo` segundos por dispositivo,
     * usando un cache lock como guardia ante requests concurrentes.
     */
    public function marcarConexion(): void
    {
        $intervaloSeg = (int) config('impresion.ultima_conexion_intervalo', 55);
        $lockKey = "impresion:conexion:{$this->getKey()}";

        // Si otro request ya actualizó dentro del intervalo, se omite la escritura.
        $lock = Cache::lock($lockKey, $intervaloSeg);

        if (! $lock->get()) {
            return;
        }

        $this->forceFill(['ultima_conexion' => now()])->save();
    }
}
