<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductUnit> */
class ProductUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'unit_id' => Unit::factory(),
            'conversion' => 1,
            'barcode' => fake()->unique()->ean13(),
            'price' => 5000,
        ];
    }
}
