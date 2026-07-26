<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Eloquent;

use App\Domains\Tenancy\Exceptions\CrossTenantWriteException;
use App\Domains\Tenancy\Exceptions\UnsafeBulkWriteException;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query builder for models using BelongsToTenant. It closes the write
 * paths that Eloquent global scopes cannot reach on their own.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantScopedBuilder extends Builder
{
    /**
     * The only way to query across tenants. Deliberately explicit: reading
     * another tenant's data is a decision that must be visible at the call site.
     */
    public function withoutTenancy(): static
    {
        return $this->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if (array_key_exists('tenant_id', $values) || array_key_exists($this->getModel()->qualifyColumn('tenant_id'), $values)) {
            throw CrossTenantWriteException::onMassUpdate($this->getModel()::class);
        }

        return parent::update($values);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        throw UnsafeBulkWriteException::insert($this->getModel()::class);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw UnsafeBulkWriteException::upsert($this->getModel()::class);
    }
}
