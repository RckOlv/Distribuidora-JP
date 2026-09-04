<?php

namespace Database\Seeders;

use App\Models\CajaFisica;
use Illuminate\Database\Seeder;

class CajaFisicaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Garantiza la existencia de las cajas físicas del negocio. Es idempotente:
     * ejecutar nuevamente el seed no crea duplicados.
     */
    public function run(): void
    {
        CajaFisica::firstOrCreate(['nombre' => 'Caja 1']);
        CajaFisica::firstOrCreate(['nombre' => 'Caja 2']);
    }
}
