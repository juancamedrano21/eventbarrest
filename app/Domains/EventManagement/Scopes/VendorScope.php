<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Scopes;

use App\Domains\EventManagement\VendorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class VendorScope implements Scope
{
    /**
     * Con negocio activo, solo sus filas. Sin negocio activo, la vista
     * consolidada de la cuenta — legítima para el equipo del organizador, y
     * segura porque TenantScope ya impide ver otra cuenta.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(VendorContext::class);

        if ($context->check()) {
            $builder->where($model->qualifyColumn('vendor_id'), $context->id());
        }
    }
}
