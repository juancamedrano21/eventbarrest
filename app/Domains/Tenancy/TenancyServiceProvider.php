<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\EventManagement\VendorContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped (not singleton): fresh instance per request/job so tenant
        // state cannot leak between requests under Octane or queue workers.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(VendorContext::class);
    }
}
