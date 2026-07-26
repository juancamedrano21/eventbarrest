<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $startsAt = now()->addWeeks(2)->setTime(18, 0);

        return [
            'name' => 'Festival '.fake()->unique()->words(2, true),
            'venue' => fake()->city(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(2),
            'status' => EventStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => EventStatus::Active]);
    }

    public function closed(): static
    {
        return $this->state(['status' => EventStatus::Closed]);
    }
}
