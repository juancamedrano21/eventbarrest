<?php

declare(strict_types=1);

use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Middleware\SetTenantContext;
use App\Http\Middleware\AuthenticateKdsDevice;
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
        // A esta aplicación se llega SIEMPRE por delante de un proxy que
        // termina el TLS: el túnel de pruebas, y mañana el balanceador de
        // producción. Sin esto Laravel ve la petición como http —el proxy le
        // habla en claro por dentro— y construye todas sus URLs con http://:
        // los assets de Vite se bloquean por contenido mixto, las redirecciones
        // del login sacan al usuario de la sesión segura, y la cookie de sesión
        // no se marca como Secure.
        //
        // Se confía en cualquier proxy porque no hay forma de conocer de
        // antemano las direcciones de un túnel ni las de un balanceador
        // gestionado. Es seguro mientras nadie pueda hablar con el servidor
        // saltándose el proxy — el día que se exponga el puerto directamente,
        // esta línea deja de valer y hay que acotar las direcciones.
        $middleware->trustProxies(at: '*');

        // Las páginas de Filament reautorizan en CADA petición de Livewire
        // (hook de hidratación), no solo al montar: buscar en una tabla,
        // paginar o abrir un modal pasan por el endpoint global de Livewire,
        // que no hereda el stack del panel. Sin el contexto ahí, esa petición
        // llega sin equipo de permisos y responde 403 aunque la pantalla se
        // hubiera abierto bien.
        //
        // Ojo: esto NO cubre las rutas de Filament — se arma su propio stack
        // sin el grupo web. /saas-admin lo cubre por su lado, con
        // authMiddleware(..., isPersistent: true) en AdminPanelProvider.
        $middleware->web(append: [
            SetTenantContext::class,
        ]);

        // La puerta de la tablet. Alias y no grupo: se monta en las rutas del
        // KDS y en ningún sitio más, porque es la única cadena de la
        // plataforma donde el actor no es una persona.
        $middleware->alias([
            'kds.device' => AuthenticateKdsDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Las reglas del dominio de ventas son errores operables del POS,
        // no fallos del servidor: 422 con el mensaje en español.
        $exceptions->render(function (SalesException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
            }

            return null;
        });

        // El tablero de cocina solo lo consume la tablet, así que aquí no hay
        // rama HTML que atender: siempre JSON. Y el status lo trae la propia
        // excepción — perder la carrera contra otra tablet es un 409, no un
        // 422, y la app repinta en vez de culpar a quien tocó.
        $exceptions->render(function (KitchenException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
