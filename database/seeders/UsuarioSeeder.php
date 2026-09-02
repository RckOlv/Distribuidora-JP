<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Usuario::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@verduleria.local')],
            [
                'name' => 'Dueño',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'password')),
                'rol_id' => Rol::where('nombre', Rol::DUENO)->value('id'),
            ],
        );

        Usuario::firstOrCreate(
            ['email' => env('SEED_CAJERO_EMAIL', 'cajero@verduleria.local')],
            [
                'name' => 'Cajero',
                'password' => Hash::make(env('SEED_CAJERO_PASSWORD', 'password')),
                'rol_id' => Rol::where('nombre', Rol::CAJERO)->value('id'),
            ],
        );
    }
}