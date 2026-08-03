<?php

declare(strict_types=1);

use App\Domains\Tenancy\Middleware\SetTenantContext;
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
use App\Http\Middleware\EnsurePosCapability;
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
