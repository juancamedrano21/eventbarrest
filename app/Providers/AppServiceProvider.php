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

        // El alta de una tablet: techo absoluto contra una inundación, por
        // código de comercio + IP. Es a propósito holgado —diez por minuto—
        // porque este limitador cuenta también los ACIERTOS, y en un montaje
        // todas las tabletas salen por el mismo NAT: apretarlo aquí dejaría
        // fuera a la sexta tablet sin que nadie se hubiera equivocado. El
        // freno que de verdad protege el PIN cuenta solo los fallos y está
        // escrito a mano en KdsEnrollController.
        RateLimiter::for('kds-enrolar', fn (Request $r) => Limit::perMinute(10)
            ->by(mb_strtoupper((string) $r->input('codigo')).'|'.$r->ip()));

        //
    }
}
