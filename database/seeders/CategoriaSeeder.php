<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriaSeeder extends Seeder
{
    /**
     * @return list<array{name: string}>
     */
    private function categorias(): array
    {
        return [
            ['name' => 'Frutas'],
            ['name' => 'Verduras'],
            ['name' => 'Bolsas'],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->categorias() as $categoria) {
            Categoria::firstOrCreate(['nombre' => $categoria['name']]);
        }
    }
}
