<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatingUnit>
 */
class OperatingUnitFactory extends Factory
{
    protected $model = OperatingUnit::class;

    public function definition(): array
    {
        return [
            'name' => 'Sucursal '.fake()->unique()->citySuffix(),
            'kind' => OperatingUnitKind::Mixed,
            'status' => OperatingUnitStatus::Active,
        ];
    }

    public function inEvent(Event $event): static
    {
        return $this->state([
            'event_id' => $event->id,
            'name' => 'Barra '.fake()->unique()->word(),
            'kind' => OperatingUnitKind::Bar,
        ]);
    }

    public function kitchen(): static
    {
        return $this->state(['kind' => OperatingUnitKind::Kitchen]);
    }
}
