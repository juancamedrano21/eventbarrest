<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => 'Categoría '.fake()->unique()->word(),
            'dispatch' => DispatchArea::Bar,
        ];
    }

    public function kitchen(): static
    {
        return $this->state(['dispatch' => DispatchArea::Kitchen]);
    }
}
