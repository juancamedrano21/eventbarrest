<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * La factory respeta los mundos: instancia la clase de cuenta que
     * corresponde al tipo, para que un Tenant de test se comporte igual que
     * uno real (relaciones y polimorfismo incluidos).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Tenant
    {
        $type = TenantType::coerce($attributes['type'] ?? TenantType::Business);

        $class = $type->accountClass();

        return new $class($attributes);
    }

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'rnc' => fake()->unique()->numerify('#########'),
            'type' => TenantType::Business,
            'status' => TenantStatus::Active,
        ];
    }

    public function organizer(): static
    {
        return $this->state(['type' => TenantType::Organizer]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }

    public function trial(): static
    {
        return $this->state(['status' => TenantStatus::Trial]);
    }
}
