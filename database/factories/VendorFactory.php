<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'rnc' => fake()->optional()->numerify('#########'),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->numerify('809-###-####'),
            'status' => VendorStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => VendorStatus::Suspended]);
    }
}
