<?php

declare(strict_types=1);

namespace App\Domains\Business\Models;

use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Support\Eloquent\IsChildModel;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta del mundo de los negocios: bar, restaurante o discoteca con
 * operación permanente. Su estructura son sucursales — esta clase no sabe
 * lo que es un evento.
 */
class BusinessAccount extends Tenant
{
    use IsChildModel;

    public static function childTypeValue(): string
    {
        return TenantType::Business->value;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function isBusiness(): bool
    {
        return true;
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'tenant_id');
    }
}
