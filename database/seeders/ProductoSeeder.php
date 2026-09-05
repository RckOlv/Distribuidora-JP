<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Costo;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Ubicacion;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    /**
     * @return list<array{categoria: string, nombre: string, unidad: string, costo: float, precio: float, stock: float, codigo?: string, activo?: bool}>
     */
    private function productos(): array
    {
        return [
            ['categoria' => 'Frutas', 'nombre' => 'Banana', 'unidad' => 'KILOGRAMO', 'costo' => 1700, 'precio' => 2500, 'stock' => 80],
            ['categoria' => 'Frutas', 'nombre' => 'Manzana Roja', 'unidad' => 'KILOGRAMO', 'costo' => 2200, 'precio' => 3200, 'stock' => 60],
            ['categoria' => 'Frutas', 'nombre' => 'Naranja', 'unidad' => 'KILOGRAMO', 'costo' => 1200, 'precio' => 1800, 'stock' => 100],
            ['categoria' => 'Verduras', 'nombre' => 'Papa', 'unidad' => 'KILOGRAMO', 'costo' => 1000, 'precio' => 1500, 'stock' => 150],
            ['categoria' => 'Verduras', 'nombre' => 'Cebolla', 'unidad' => 'KILOGRAMO', 'costo' => 1100, 'precio' => 1600, 'stock' => 90],
            ['categoria' => 'Verduras', 'nombre' => 'Tomate', 'unidad' => 'KILOGRAMO', 'costo' => 2400, 'precio' => 3500, 'stock' => 40],
            ['categoria' => 'Verduras', 'nombre' => 'Lechuga', 'unidad' => 'UNIDAD', 'costo' => 750, 'precio' => 1200, 'stock' => 30],
            ['categoria' => 'Bolsas', 'nombre' => 'Bolsa Sopa', 'unidad' => 'BOLSA', 'costo' => 2800, 'precio' => 4000, 'stock' => 20, 'codigo' => '7791234567890'],
            ['categoria' => 'Verduras', 'nombre' => 'Cilantro', 'unidad' => 'KILOGRAMO', 'costo' => 1300, 'precio' => 2000, 'stock' => 15, 'activo' => false],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $local = Ubicacion::where('nombre', 'LOCAL')->firstOrFail();

        foreach ($this->productos() as $datos) {
            $producto = Producto::firstOrCreate(
                ['nombre' => $datos['nombre']],
                [
                    'categoria_id' => Categoria::where('nombre', $datos['categoria'])->value('id'),
                    'codigo' => $datos['codigo'] ?? null,
                    'unidad_medida' => $datos['unidad'],
                    'activo' => $datos['activo'] ?? true,
                ],
            );

            Precio::firstOrCreate(
                ['producto_id' => $producto->id, 'vigente' => true],
                ['monto' => $datos['precio']],
            );

            Costo::firstOrCreate(
                ['producto_id' => $producto->id, 'vigente' => true],
                ['precio' => $datos['costo']],
            );

            $producto->stocks()->firstOrCreate(
                ['ubicacion_id' => $local->id],
                ['cantidad' => $datos['stock']],
            );
        }
    }
}
