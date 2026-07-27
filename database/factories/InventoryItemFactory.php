<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'name' => 'Insumo '.fake()->unique()->word(),
            'base_unit' => MeasurementUnit::Milliliter,
            'cost_cents' => fake()->numberBetween(1, 200),
        ];
    }

    public function unit(int $costCents = 5000): static
    {
        return $this->state([
            'base_unit' => MeasurementUnit::Unit,
            'cost_cents' => $costCents,
        ]);
    }
}
