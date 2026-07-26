<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Queue payloads carry tenant data, so the dashboard is closed to
     * everyone outside local development until the Identity domain lands
     * platform-staff roles. Declared explicitly rather than relying on
     * Horizon's implicit default, so opening it later is a visible decision.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            return $this->app->environment('local');
        });
    }
}
