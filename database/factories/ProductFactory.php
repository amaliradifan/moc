<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'     => fake()->words(3, true),
            'sku'      => fake()->unique()->bothify('SKU-####-???'),
            'price'    => fake()->numberBetween(10000, 5000000),
            'stock'    => fake()->numberBetween(0, 1000),
            'quantity' => fake()->numberBetween(10, 500),
            'is_active' => true,
        ];
    }
}
