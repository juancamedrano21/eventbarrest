<?php

declare(strict_types=1);

use App\Domains\Identity\Queries\HomeForUser;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EventPanel\DashboardController;
use App\Http\Controllers\EventPanel\EventsController;
use App\Http\Controllers\EventPanel\VendorCatalogController;
use App\Http\Controllers\EventPanel\VendorInventoryController;
use App\Http\Controllers\EventPanel\VendorProfileController;
use App\Http\Controllers\EventPanel\VendorSalesController;
use App\Http\Controllers\EventPanel\VendorsController;
use App\Http\Controllers\EventVendor\CatalogController as ComercioCatalogController;
use App\Http\Controllers\EventVendor\HomeController as ComercioHomeController;
use App\Http\Controllers\EventVendor\InventoryController as ComercioInventoryController;
use App\Http\Controllers\EventVendor\SalesController as ComercioSalesController;
use App\Http\Middleware\EnsureEventVendorUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// La raíz no es un panel: es un desvío. Con sesión, a la puerta de cada
// quien (ADR-007); sin ella, a entrar. El día que haya sitio público de
// marketing, ese ocupa la raíz y el desvío se vuelve un enlace «Entrar».
Route::get('/', function (Request $request) {
    $user = $request->user();

    return redirect($user instanceof User ? app(HomeForUser::class)($user) : '/entrar');
});

// La pantalla del POS: cascaron publico, el estado vive en el dispositivo
// y toda la autorizacion en la API por token.
// Dos puertas para el punto de venta, una por modalidad: cada una se
// instala como su propia app (nombre, icono y arranque propios) y el
// cajero solo ve la suya. El motor offline es el mismo por debajo — la
// pieza más delicada del sistema no se mantiene por duplicado.
Route::view('/pos', 'pos', ['modalidad' => 'business', 'titulo' => 'POS', 'manifest' => '/pos-manifest.webmanifest'])
    ->name('pos');
Route::view('/event-pos', 'pos', ['modalidad' => 'event', 'titulo' => 'POS Eventos', 'manifest' => '/event-pos-manifest.webmanifest'])
    ->name('event-pos');

// El panel NUEVO (Blade + Preline, ADR-006): convive con Filament hasta la
// paridad. La autenticación es la misma sesión del panel clásico.
// La entrada única (ADR-007): una pantalla, y cada quien a SU puerta.
Route::get('/entrar', [LoginController::class, 'show'])->name('login');
Route::post('/entrar', [LoginController::class, 'store'])->name('login.store');
Route::post('/salir', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// El nombre 'login' es el que usa el middleware auth para mandar de vuelta
// a los invitados; /login queda como atajo humano.
Route::redirect('/login', '/entrar');

Route::middleware(['auth'])->prefix('event-panel')->name('event-panel.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('home');
    Route::get('/comercios', [VendorsController::class, 'index'])->name('vendors.index');
    Route::post('/comercios', [VendorsController::class, 'store'])->name('vendors.store');
    Route::post('/comercios/{vendor}/datos', [VendorsController::class, 'update'])->name('vendors.update');
    Route::get('/eventos', [EventsController::class, 'index'])->name('events.index');
    Route::post('/eventos', [EventsController::class, 'store'])->name('events.store');
    Route::get('/eventos/{event}', [EventsController::class, 'show'])->name('events.show');
    Route::get('/comercios/{vendor}', [VendorProfileController::class, 'show'])->name('vendors.show');
    Route::get('/comercios/{vendor}/ventas/{order}', [VendorSalesController::class, 'show'])->name('vendors.sales.show');
    Route::post('/comercios/{vendor}/usuarios', [VendorProfileController::class, 'storeUser'])->name('vendors.users.store');
    Route::post('/comercios/{vendor}/invitar', [VendorProfileController::class, 'invite'])->name('vendors.invite');
    Route::post('/comercios/{vendor}/puestos', [VendorProfileController::class, 'storeOutlet'])->name('vendors.outlets.store');
    Route::post('/comercios/{vendor}/categorias', [VendorCatalogController::class, 'storeCategory'])->name('vendors.categories.store');
    Route::post('/comercios/{vendor}/productos', [VendorCatalogController::class, 'storeProduct'])->name('vendors.products.store');
    Route::post('/comercios/{vendor}/productos/{product}', [VendorCatalogController::class, 'updateProduct'])->name('vendors.products.update');
    Route::post('/comercios/{vendor}/productos/{product}/receta', [VendorCatalogController::class, 'storeRecipeItem'])->name('vendors.recipe.store');
    Route::post('/comercios/{vendor}/productos/{product}/receta/{item}/eliminar', [VendorCatalogController::class, 'destroyRecipeItem'])->name('vendors.recipe.destroy');
    Route::post('/comercios/{vendor}/insumos', [VendorInventoryController::class, 'storeItem'])->name('vendors.items.store');
    Route::post('/comercios/{vendor}/compras', [VendorInventoryController::class, 'storePurchase'])->name('vendors.purchases.store');
});

// La puerta del personal del comercio (ADR-007): su comercio es implícito
// por su usuario — jamás elegido por URL. El middleware rebota a cada
// audiencia a SU puerta.
Route::middleware(['auth', EnsureEventVendorUser::class])->prefix('event-vendor')->name('event-vendor.')->group(function (): void {
    Route::get('/', ComercioHomeController::class)->name('home');
    Route::get('/ventas/{order}', [ComercioSalesController::class, 'show'])->name('sales.show');
    Route::post('/categorias', [ComercioCatalogController::class, 'storeCategory'])->name('categories.store');
    Route::post('/productos', [ComercioCatalogController::class, 'storeProduct'])->name('products.store');
    Route::post('/productos/{product}', [ComercioCatalogController::class, 'updateProduct'])->name('products.update');
    Route::post('/productos/{product}/receta', [ComercioCatalogController::class, 'storeRecipeItem'])->name('recipe.store');
    Route::post('/productos/{product}/receta/{item}/eliminar', [ComercioCatalogController::class, 'destroyRecipeItem'])->name('recipe.destroy');
    Route::post('/insumos', [ComercioInventoryController::class, 'storeItem'])->name('items.store');
    Route::post('/compras', [ComercioInventoryController::class, 'storePurchase'])->name('purchases.store');
});
