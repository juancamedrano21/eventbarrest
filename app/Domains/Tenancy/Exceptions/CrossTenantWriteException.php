<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a write would land on — or move a row to — a tenant other
 * than the active one. Writes are fail-closed and symmetric with reads:
 * crossing tenants is only ever possible through TenantContext::runAs().
 */
class CrossTenantWriteException extends RuntimeException
{
    public static function onCreate(string $model, int $given, int $active): self
    {
        return new self(
            "Refusing to create [{$model}] for tenant [{$given}] while tenant [{$active}] is active. ".
            'Use TenantContext::runAs() to act for another tenant explicitly.'
        );
    }

    public static function onTenantChange(string $model): self
    {
        return new self(
            "The tenant_id of [{$model}] is immutable: a record cannot be moved between tenants. ".
            'Delete it and recreate it under the target tenant if that is really the intent.'
        );
    }

    public static function onMassUpdate(string $model): self
    {
        return new self(
            "Refusing a mass update that writes tenant_id on [{$model}]. ".
            'tenant_id is immutable; remove it from the update payload.'
        );
    }
}
