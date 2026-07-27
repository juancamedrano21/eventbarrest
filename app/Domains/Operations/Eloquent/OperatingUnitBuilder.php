<?php

declare(strict_types=1);

namespace App\Domains\Operations\Eloquent;

use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los updates masivos no disparan eventos de modelo, así que el hook que
 * protege la estructura (event_id, type) no llega a correr: se bloquea aquí,
 * igual que TenantScopedBuilder hace con tenant_id.
 *
 * @template TModel of Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class OperatingUnitBuilder extends TenantScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $model = $this->getModel();

        foreach (['event_id', 'type'] as $column) {
            if (array_key_exists($column, $values) || array_key_exists($model->qualifyColumn($column), $values)) {
                throw InvalidOperatingUnitException::structureIsImmutable();
            }
        }

        return parent::update($values);
    }
}
