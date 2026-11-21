<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'base_unit_id' => Unit::factory(),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->words(3, true),
            'min_stock' => 0,
            'is_active' => true,
        ];
    }
}
