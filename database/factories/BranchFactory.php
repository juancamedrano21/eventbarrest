<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Business\Models\Branch;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'Sucursal '.fake()->unique()->citySuffix(),
            'kind' => OperatingUnitKind::Mixed,
            'status' => OperatingUnitStatus::Active,
        ];
    }

    public function kitchen(): static
    {
        return $this->state(['kind' => OperatingUnitKind::Kitchen]);
    }
}
