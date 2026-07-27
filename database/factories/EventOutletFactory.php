<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventOutlet>
 */
class EventOutletFactory extends Factory
{
    protected $model = EventOutlet::class;

    public function definition(): array
    {
        return [
            'name' => 'Barra '.fake()->unique()->word(),
            'kind' => OperatingUnitKind::Bar,
            'status' => OperatingUnitStatus::Active,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(['event_id' => $event->id]);
    }

    public function kitchen(): static
    {
        return $this->state([
            'name' => 'Cocina '.fake()->unique()->word(),
            'kind' => OperatingUnitKind::Kitchen,
        ]);
    }
}
