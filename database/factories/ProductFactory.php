<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'category_id' => Category::factory(),
            'sku' => strtoupper(Str::random(8)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 19.9, 1999.9),
            'stock' => fake()->numberBetween(1, 200),
            // Desconto Checkout (Pix) que todo produto já cadastrado recebeu em 2026-10-18.
            'checkout_discount_percentage' => 5,
            'is_active' => fake()->boolean(90),
        ];
    }
}
