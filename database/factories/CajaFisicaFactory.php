<?php

namespace Database\Factories;

use App\Models\CajaFisica;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaFisica>
 */
class CajaFisicaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Caja '.fake()->unique()->numberBetween(1, 9999),
            'activa' => true,
        ];
    }
}
