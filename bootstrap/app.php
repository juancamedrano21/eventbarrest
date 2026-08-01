<?php

declare(strict_types=1);

use App\Domains\Sales\Exceptions\SalesException;
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
        // Las páginas de Filament reautorizan en CADA petición de Livewire
        // (hook de hidratación), no solo al montar: buscar en una tabla,
        // paginar o abrir un modal pasan por el endpoint global de Livewire,
        // que no hereda el stack del panel. Sin el contexto ahí, esa petición
        // llega sin equipo de permisos y responde 403 aunque la pantalla se
        // hubiera abierto bien.
        //
        // Ojo: esto NO cubre las rutas del panel — Filament les arma su propio
        // stack sin el grupo web. El panel lo cubre por su lado, con
        // authMiddleware(..., isPersistent: true) en AppPanelProvider.
        $middleware->web(append: [
            SetTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Las reglas del dominio de ventas son errores operables del POS,
        // no fallos del servidor: 422 con el mensaje en español.
        $exceptions->render(function (SalesException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
