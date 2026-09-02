<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use App\Support\Permisos;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    /**
     * Permisos del rol CAJERO: únicamente punto de venta.
     *
     * @return list<string>
     */
    private function permisosCajero(): array
    {
        return [
            Permisos::POS_USAR,
            Permisos::VENTAS_REALIZAR,
            Permisos::VENTAS_VER,
            Permisos::TICKETS_IMPRIMIR,
            Permisos::CAJAS_USAR,
            Permisos::CAJAS_VER,
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Permisos::todos() as $nombre) {
            Permiso::firstOrCreate(['nombre' => $nombre]);
        }

        $dueno = Rol::firstOrCreate(
            ['nombre' => Rol::DUENO],
            ['descripcion' => 'Acceso total al sistema'],
        );
        $dueno->permisos()->sync(Permiso::all()->pluck('id'));

        $cajero = Rol::firstOrCreate(
            ['nombre' => Rol::CAJERO],
            ['descripcion' => 'Acceso únicamente al punto de venta'],
        );
        $cajero->permisos()->sync(
            Permiso::whereIn('nombre', $this->permisosCajero())->pluck('id')
        );
    }
}
