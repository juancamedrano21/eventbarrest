<?php

declare(strict_types=1);

use App\Domains\Tenancy\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En el grupo web, no en el panel: Filament navega por SPA y pide el
        // contenido a la ruta de Livewire, que es global y no hereda los
        // middleware del panel. Sin el contexto ahí, el usuario llega sin
        // equipo de permisos y todas las pantallas responden 403 mientras el
        // menú —pintado en la carga inicial— se ve correcto.
        $middleware->web(append: [
            SetTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
