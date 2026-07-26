<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Exceptions\MissingTenantContextException;
use Closure;

/**
 * Holds the tenant the current request/job/command is acting for.
 * Registered as a scoped singleton: state never leaks across requests
 * or queued jobs under Octane/Horizon.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContextException::forRead();
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Run a callback as the given tenant, restoring the previous
     * context afterwards even if the callback throws.
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
