<?php

namespace App\Console\Commands;

use App\Models\DispositivoImpresion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GestionarDispositivoImpresion extends Command
{
    protected $signature = 'impresion:dispositivo
        {accion : crear|listar|desactivar|activar}
        {--nombre= : Nombre del dispositivo (para crear)}';

    protected $description = 'Gestiona los dispositivos de impresión (print bridges) y sus tokens.';

    /**
     * Run the command.
     */
    public function handle(): int
    {
        return match ($this->argument('accion')) {
            'crear' => $this->crear(),
            'listar' => $this->listar(),
            default => $this->cambiarEstado(),
        };
    }

    private function crear(): int
    {
        $nombre = $this->option('nombre');

        if ($nombre === null || $nombre === '') {
            $this->error('Indicá el nombre con --nombre=bridge-001.');

            return self::FAILURE;
        }

        if (DispositivoImpresion::where('nombre', $nombre)->exists()) {
            $this->error("Ya existe un dispositivo llamado «{$nombre}».");

            return self::FAILURE;
        }

        $token = 'bridge_'.Str::random(40);

        DispositivoImpresion::create([
            'nombre' => $nombre,
            'token_hash' => Hash::make($token),
            'activo' => true,
        ]);

        $this->info("Dispositivo «{$nombre}» creado.");
        $this->line('Token (cópialo en config.json del bridge; no se muestra de nuevo):');
        $this->line($token);

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $filas = DispositivoImpresion::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'activo', 'ultima_conexion'])
            ->map(fn (DispositivoImpresion $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'activo' => $d->activo ? 'si' : 'no',
                'ultima_conexion' => $d->ultima_conexion?->format('d/m/Y H:i:s') ?? '—',
            ]);

        $this->table(['ID', 'Nombre', 'Activo', 'Última conexión'], $filas);

        return self::SUCCESS;
    }

    private function cambiarEstado(): int
    {
        $nombre = $this->option('nombre');

        if ($nombre === null || $nombre === '') {
            $this->error('Indicá el nombre con --nombre=bridge-001.');

            return self::FAILURE;
        }

        $dispositivo = DispositivoImpresion::where('nombre', $nombre)->first();

        if ($dispositivo === null) {
            $this->error("No existe un dispositivo llamado «{$nombre}».");

            return self::FAILURE;
        }

        $activo = $this->argument('accion') === 'activar';

        $dispositivo->update(['activo' => $activo]);

        $this->info("Dispositivo «{$nombre}» ".($activo ? 'activado.' : 'desactivado.'));

        return self::SUCCESS;
    }
}
