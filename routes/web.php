<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\VendorProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// La pantalla del POS: cascaron publico, el estado vive en el dispositivo
// y toda la autorizacion en la API por token.
Route::view('/pos', 'pos');

// El panel NUEVO (Blade + Preline, ADR-006): convive con Filament hasta la
// paridad. La autenticación es la misma sesión del panel clásico.
Route::redirect('/login', '/app/login')->name('login');

Route::middleware(['auth'])->prefix('panel')->name('panel.')->group(function (): void {
    Route::redirect('/', '/app')->name('home');
    Route::get('/comercios/{vendor}', [VendorProfileController::class, 'show'])->name('vendors.show');
    Route::post('/comercios/{vendor}/usuarios', [VendorProfileController::class, 'storeUser'])->name('vendors.users.store');
    Route::post('/comercios/{vendor}/invitar', [VendorProfileController::class, 'invite'])->name('vendors.invite');
    Route::post('/comercios/{vendor}/puestos', [VendorProfileController::class, 'storeOutlet'])->name('vendors.outlets.store');
});
