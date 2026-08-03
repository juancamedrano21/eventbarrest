<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        // El tema Preline Pro (licenciado) vive fuera de git: si está
        // restaurado, las pantallas del panel usan su layout; si no, el
        // layout simple — así los tests y un clon fresco funcionan igual.
        if (is_dir(resource_path('panel-theme/views'))) {
            View::addNamespace('paneltheme', resource_path('panel-theme/views'));
            View::share('panelLayout', 'paneltheme::layout');
        } else {
            View::share('panelLayout', 'event-panel.layout');
        }

        // El login del POS es una puerta pública de la API: 5 intentos por
        // minuto por usuario+IP frenan fuerza bruta y credential stuffing.
        //
        // La llave se compone con `username`, que es lo que ese endpoint
        // valida de verdad. Decía `email`, que nunca llega, así que la llave
        // efectiva era «|IP»: en un festival, donde todas las cajas salen por
        // el mismo NAT, la sexta tablet recibía 429 sin que nadie fallara —
        // y encima ThrottleRequests cuenta también los ACIERTOS.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->input('username').'|'.$request->ip()));

        //
    }
}
