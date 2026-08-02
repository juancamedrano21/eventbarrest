<?php

declare(strict_types=1);

use App\Domains\Tenancy\Middleware\SetTenantContext;
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
