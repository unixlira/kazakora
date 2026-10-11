<?php

namespace Database\Factories;

use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Padrão', 'Expressa', 'Econômica']),
            'estimated_days' => fake()->numberBetween(1, 10),
            'price' => fake()->randomFloat(2, 0, 40),
            'is_active' => true,
        ];
    }
}
