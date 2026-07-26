<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Scopes;

use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * Fail closed: without an active tenant context a scoped query
     * matches nothing, so a missing middleware or a platform-level
     * code path can never leak another tenant's rows by accident.
     * Cross-tenant reads must be explicit via withoutTenancy().
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->check()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());
        } else {
            $builder->whereRaw('1 = 0');
        }
    }
}
