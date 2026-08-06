<?php

declare(strict_types=1);

use App\Domains\Tenancy\Middleware\SetTenantContext;
use App\Http\Controllers\EventApp\EventAppAccountController;
use App\Http\Controllers\EventApp\EventAppManifestController;
use App\Http\Controllers\EventApp\EventAppMenuController;
use App\Http\Controllers\EventApp\EventAppVendorsController;
use App\Http\Controllers\Kds\KdsBoardController;
use App\Http\Controllers\Kds\KdsEnrollController;
use App\Http\Controllers\Kds\KdsSearchController;
use App\Http\Controllers\Kds\KdsTicketController;
use App\Http\Controllers\Pos\PosAuthController;
use App\Http\Controllers\Pos\PosBootstrapController;
use App\Http\Controllers\Pos\PosCashSessionController;
use App\Http\Controllers\Pos\PosCatalogController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\Pos\PosSalesController;
use App\Http\Middleware\AuthenticateEventAppAccount;
use App\Http\Middleware\EnsurePosCapability;
use App\Http\Middleware\ResolveEventAppContext;
use Illuminate\Support\Facades\Route;

Route::post('/pos/login', [PosAuthController::class, 'login'])->middleware('throttle:pos-login');

// El contexto (cuenta + comercio) se fija por token igual que en el panel:
// los scopes hacen que lo ajeno simplemente no exista.
Route::middleware(['auth:sanctum', SetTenantContext::class, EnsurePosCapability::class])
    ->prefix('pos')
    ->group(function (): void {
        Route::get('/bootstrap', PosBootstrapController::class);
        Route::get('/catalog', PosCatalogController::class);
        Route::post('/sessions', [PosCashSessionController::class, 'store']);
        Route::post('/sessions/{cashSession}/close', [PosCashSessionController::class, 'close']);
        Route::post('/orders', [PosOrderController::class, 'store']);
        Route::get('/sales', [PosSalesController::class, 'index']);
        Route::post('/sales/{order}/refund', [PosSalesController::class, 'refund']);
        Route::post('/logout', [PosAuthController::class, 'logout']);
    });

// El alta de la tablet va suelta: es la única puerta del KDS que se abre sin
// token, porque quien llama todavía no tiene ninguno. El throttle es el techo
// contra una inundación; el freno de verdad —el que solo cuenta los fallos—
// está escrito a mano dentro del controlador.
Route::post('/kds/enrolar', [KdsEnrollController::class, 'enrolar'])->middleware('throttle:kds-enrolar');

// Y el resto, detrás del token del dispositivo. No se reaprovecha la cadena
// del POS a propósito: aquí el actor no es una persona, y auth:sanctum sobre
// una petición sin usuario dejaría el contexto limpio, TenantScope emitiría
// `where 1 = 0` y la tablet recibiría un 200 con el tablero vacío.
Route::middleware('kds.device')
    ->prefix('kds')
    ->group(function (): void {
        Route::get('/comandas', KdsBoardController::class);
        Route::post('/comandas/{order}/{area}/estado', [KdsTicketController::class, 'estado']);
        Route::get('/buscar', KdsSearchController::class);
        Route::post('/salir', [KdsEnrollController::class, 'salir']);
    });

// La puerta de la app del asistente: pública de verdad, sin token de nada.
// El asistente no tiene cuenta todavía y el catálogo de un festival es lo
// mismo que está impreso en el cartel del puesto, así que no hay nada que
// autenticar — y como no hay nada que escribir, tampoco hay nada que
// proteger con una sesión.
//
// El contexto sale del EVENTO de la URL, y por eso este middleware es el
// primero de la cadena: sin él, TenantScope falla cerrado y los tres
// endpoints contestan 200 con todo vacío.
//
// Y SIN `throttle`, a conciencia. Tuvo uno por (evento, IP) y se quitó: con
// `trustProxies(at: '*')` la IP la escribe quien llama, así que el cubo no
// cuenta contra quien ataca —estrena IP en cada petición— y sí contra el
// público, que en un festival sale entero por el NAT de dos operadores; peor
// aún, cualquiera podía llenar el cubo de una IP ajena con una cabecera y
// dejar sin app a todo el que saliera por ella. El razonamiento largo está en
// AppServiceProvider, donde estaba el limitador, y el techo de volumen que sí
// hace falta va en el borde.
Route::middleware([ResolveEventAppContext::class])
    ->prefix('event-app')
    ->group(function (): void {
        Route::get('/eventos/{codigo}/manifiesto', EventAppManifestController::class);
        Route::get('/eventos/{codigo}/comercios', EventAppVendorsController::class);
        Route::get('/eventos/{codigo}/comercios/{comercio}/menu', EventAppMenuController::class);
    });

// La cuenta del asistente: el primer actor de plataforma que no es el
// superadmin. FUERA de ResolveEventAppContext a propósito — la cuenta no es
// de ningún evento (es la identidad que mañana ata boleta, pulsera y
// monedero a través de festivales), así que aquí no hay código de evento en
// la URL ni tenant que resolver. Y fuera de auth:sanctum por las dos trampas
// documentadas en AuthenticateKdsDevice; el token del asistente vive en su
// tabla propia y lo revalida AuthenticateEventAppAccount en cada petición.
//
// Pedir código y entrar van sueltas: quien llama todavía no tiene sesión.
// Los frenos de emisión de códigos —por buzón de destino y un cortacircuitos
// global de volumen, nunca por IP— están dentro del controlador, con su
// porqué y sus números.
Route::prefix('event-app/cuenta')
    ->group(function (): void {
        Route::post('/codigo', [EventAppAccountController::class, 'codigo']);
        Route::post('/entrar', [EventAppAccountController::class, 'entrar']);

        Route::middleware(AuthenticateEventAppAccount::class)->group(function (): void {
            Route::get('', [EventAppAccountController::class, 'perfil']);
            Route::patch('', [EventAppAccountController::class, 'actualizar']);
            Route::post('/salir', [EventAppAccountController::class, 'salir']);
            Route::delete('', [EventAppAccountController::class, 'borrar']);
        });
    });
