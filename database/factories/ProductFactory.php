<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => 'Producto '.fake()->unique()->word(),
            'type' => ProductType::Simple,
            'price_cents' => fake()->numberBetween(10000, 60000),
            'track_stock' => false,
            'active' => true,
        ];
    }

    public function recipe(): static
    {
        return $this->state(['type' => ProductType::Recipe]);
    }
}
