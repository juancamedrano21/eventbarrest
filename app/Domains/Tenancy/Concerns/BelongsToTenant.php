<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Concerns;

use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;
use App\Domains\Tenancy\Exceptions\CrossTenantWriteException;
use App\Domains\Tenancy\Exceptions\MissingTenantContextException;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Every business model must use this trait — enforced by the architecture
 * test in tests/TenantIsolation.
 *
 * Reads are constrained by TenantScope and writes are fail-closed in the
 * same way: no active context means no write, and a row can never be
 * created for — or moved to — a tenant other than the active one.
 * Crossing tenants is only possible through TenantContext::runAs().
 *
 * Two write paths remain outside Eloquent's reach by design and are
 * blocked at the query builder instead (see TenantScopedBuilder):
 * insert() and upsert(). Raw DB::table() queries bypass everything —
 * that is inherent to a shared-database design, which is why every
 * business table also carries a NOT NULL tenant_id and composite unique
 * indexes (verified by the schema convention test).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->check()) {
                throw MissingTenantContextException::forWrite($model::class);
            }

            $given = $model->getAttribute('tenant_id');

            if ($given === null) {
                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            if ((int) $given !== $context->id()) {
                throw CrossTenantWriteException::onCreate($model::class, (int) $given, (int) $context->id());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWriteException::onTenantChange($model::class);
            }
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return TenantScopedBuilder<*>
     */
    public function newEloquentBuilder($query): TenantScopedBuilder
    {
        return new TenantScopedBuilder($query);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
