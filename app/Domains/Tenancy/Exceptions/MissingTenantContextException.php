<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public static function forWrite(string $model): self
    {
        return new self(
            "Cannot write [{$model}] without an active tenant context. ".
            'Platform-level flows (seeders, super-admin actions, console commands) must wrap the '.
            'write in TenantContext::runAs($tenant, fn () => ...) so the tenant is explicit and auditable.'
        );
    }

    public static function forRead(): self
    {
        return new self('No active tenant context. Set one via TenantContext::set() or runAs().');
    }
}
