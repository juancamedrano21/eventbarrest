<?php

declare(strict_types=1);

namespace App\Domains\Operations\Eloquent;

use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los updates masivos no disparan eventos de modelo, así que el hook que
 * protege event_id no llega a correr: se bloquea aquí, igual que
 * TenantScopedBuilder hace con tenant_id.
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
        if (array_key_exists('event_id', $values) || array_key_exists($this->getModel()->qualifyColumn('event_id'), $values)) {
            throw InvalidOperatingUnitException::eventIsImmutable();
        }

        return parent::update($values);
    }
}
