<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El login del POS es la única puerta pública de la API: 5 intentos
        // por minuto por email+IP frenan fuerza bruta y credential stuffing.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->input('email').'|'.$request->ip()));

        //
    }
}
