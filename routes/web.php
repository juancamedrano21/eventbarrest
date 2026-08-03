<?php

declare(strict_types=1);

use App\Domains\Identity\Queries\HomeForUser;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Business\BranchesController;
use App\Http\Controllers\Business\CashController as BusinessCashController;
use App\Http\Controllers\Business\HomeController as BusinessHomeController;
use App\Http\Controllers\Business\InventoryController as BusinessInventoryController;
use App\Http\Controllers\Business\MenuController as BusinessMenuController;
use App\Http\Controllers\Business\SalesController as BusinessSalesController;
use App\Http\Controllers\Business\SettingsController;
use App\Http\Controllers\Business\TeamController;
use App\Http\Controllers\EventPanel\DashboardController;
use App\Http\Controllers\EventPanel\EventsController;
use App\Http\Controllers\EventPanel\EventStockController;
use App\Http\Controllers\EventPanel\EventTimingsController;
use App\Http\Controllers\EventPanel\SettingsController as EventPanelSettingsController;
use App\Http\Controllers\EventPanel\SettlementController;
use App\Http\Controllers\EventPanel\TeamController as EventPanelTeamController;
use App\Http\Controllers\EventPanel\VendorCatalogController;
use App\Http\Controllers\EventPanel\VendorInventoryController;
use App\Http\Controllers\EventPanel\VendorKdsController;
use App\Http\Controllers\EventPanel\VendorProfileController;
use App\Http\Controllers\EventPanel\VendorSalesController;
use App\Http\Controllers\EventPanel\VendorsController;
use App\Http\Controllers\EventVendor\CatalogController as ComercioCatalogController;
use App\Http\Controllers\EventVendor\HomeController as ComercioHomeController;
use App\Http\Controllers\EventVendor\InventoryController as ComercioInventoryController;
use App\Http\Controllers\EventVendor\SalesController as ComercioSalesController;
use App\Http\Controllers\EventVendor\TimingsController as ComercioTimingsController;
use App\Http\Middleware\EnsureBusinessUser;
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

// La pantalla de cocina, del mismo palo: cascarón público y toda la
// autorización en la API, pero por token de DISPOSITIVO en vez de por
// usuario — quien la mira no se identifica, la tablet sí.
Route::view('/event-kds', 'kds', ['titulo' => 'Comandas'])->name('event-kds');

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
    Route::get('/ajustes', [EventPanelSettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/ajustes', [EventPanelSettingsController::class, 'update'])->name('settings.update');
    Route::get('/equipo', [EventPanelTeamController::class, 'index'])->name('team.index');
    Route::post('/equipo', [EventPanelTeamController::class, 'store'])->name('team.store');
    Route::post('/equipo/{user}', [EventPanelTeamController::class, 'update'])->name('team.update');
    Route::post('/equipo/{user}/eliminar', [EventPanelTeamController::class, 'destroy'])->name('team.destroy');
    Route::get('/eventos', [EventsController::class, 'index'])->name('events.index');
    Route::post('/eventos', [EventsController::class, 'store'])->name('events.store');
    Route::get('/eventos/{event}', [EventsController::class, 'show'])->name('events.show');
    Route::post('/eventos/{event}', [EventsController::class, 'update'])->name('events.update');
    Route::get('/eventos/{event}/liquidacion', [SettlementController::class, 'show'])->name('events.settlement');
    Route::post('/eventos/{event}/liquidar', [SettlementController::class, 'store'])->name('events.settle');
    Route::post('/eventos/{event}/liquidacion/{settlement}/cobrada', [SettlementController::class, 'markPaid'])->name('events.settlement.paid');
    Route::get('/eventos/{event}/mercancia', [EventStockController::class, 'show'])->name('events.stock');
    Route::post('/eventos/{event}/mercancia/entregar', [EventStockController::class, 'allocate'])->name('events.stock.allocate');
    Route::post('/eventos/{event}/mercancia/devolver', [EventStockController::class, 'returnStock'])->name('events.stock.return');
    Route::get('/eventos/{event}/tiempos', [EventTimingsController::class, 'show'])->name('events.timings');
    Route::get('/comercios/{vendor}', [VendorProfileController::class, 'show'])->name('vendors.show');
    Route::get('/comercios/{vendor}/ventas/{order}', [VendorSalesController::class, 'show'])->name('vendors.sales.show');
    Route::post('/comercios/{vendor}/usuarios', [VendorProfileController::class, 'storeUser'])->name('vendors.users.store');
    Route::post('/comercios/{vendor}/usuarios/{user}/rol', [VendorProfileController::class, 'updateUserRole'])->name('vendors.users.role');
    Route::post('/comercios/{vendor}/invitar', [VendorProfileController::class, 'invite'])->name('vendors.invite');
    Route::post('/comercios/{vendor}/eventos/{event}/comision', [VendorProfileController::class, 'updateCommission'])->name('vendors.commission.update');
    Route::post('/comercios/{vendor}/eventos/{event}/retirar', [VendorProfileController::class, 'removeFromEvent'])->name('vendors.events.remove');
    Route::post('/comercios/{vendor}/puestos', [VendorProfileController::class, 'storeOutlet'])->name('vendors.outlets.store');
    Route::post('/comercios/{vendor}/puestos/{outlet}', [VendorProfileController::class, 'updateOutlet'])->name('vendors.outlets.update');
    // Las tabletas del KDS. Tres permisos distintos en cinco rutas: el
    // código es del comercio, el PIN es del puesto y las tabletas son
    // dispositivos — quien gestiona una cosa no tiene por qué tocar las otras.
    Route::post('/comercios/{vendor}/codigo-kds', [VendorKdsController::class, 'regenerateCode'])->name('vendors.kds.code');
    Route::post('/comercios/{vendor}/puestos/{outlet}/pin-kds', [VendorKdsController::class, 'rotatePin'])->name('vendors.kds.pin');
    Route::post('/comercios/{vendor}/puestos/{outlet}/pin-kds/desbloquear', [VendorKdsController::class, 'unlockPin'])->name('vendors.kds.pin.unlock');
    Route::post('/comercios/{vendor}/tabletas/revocar-todas', [VendorKdsController::class, 'revokeAll'])->name('vendors.kds.devices.revoke-all');
    Route::post('/comercios/{vendor}/tabletas/{device}/revocar', [VendorKdsController::class, 'revokeDevice'])->name('vendors.kds.devices.revoke');
    Route::post('/comercios/{vendor}/categorias', [VendorCatalogController::class, 'storeCategory'])->name('vendors.categories.store');
    Route::post('/comercios/{vendor}/productos', [VendorCatalogController::class, 'storeProduct'])->name('vendors.products.store');
    Route::post('/comercios/{vendor}/productos/{product}', [VendorCatalogController::class, 'updateProduct'])->name('vendors.products.update');
    Route::post('/comercios/{vendor}/productos/{product}/receta', [VendorCatalogController::class, 'storeRecipeItem'])->name('vendors.recipe.store');
    Route::post('/comercios/{vendor}/productos/{product}/receta/{item}/eliminar', [VendorCatalogController::class, 'destroyRecipeItem'])->name('vendors.recipe.destroy');
    Route::post('/comercios/{vendor}/insumos', [VendorInventoryController::class, 'storeItem'])->name('vendors.items.store');
    Route::post('/comercios/{vendor}/compras', [VendorInventoryController::class, 'storePurchase'])->name('vendors.purchases.store');
    Route::post('/comercios/{vendor}/ajustes-de-stock', [VendorInventoryController::class, 'storeAdjustment'])->name('vendors.adjustments.store');
    Route::post('/comercios/{vendor}/mermas', [VendorInventoryController::class, 'storeWaste'])->name('vendors.waste.store');
    Route::post('/comercios/{vendor}/traslados', [VendorInventoryController::class, 'storeTransfer'])->name('vendors.transfers.store');
    Route::post('/comercios/{vendor}/existencias/{level}/umbral', [VendorInventoryController::class, 'updateThreshold'])->name('vendors.thresholds.update');
    Route::post('/comercios/{vendor}/usuarios/{user}/datos', [VendorProfileController::class, 'updateUser'])->name('vendors.users.update');
    Route::post('/comercios/{vendor}/usuarios/{user}/eliminar', [VendorProfileController::class, 'destroyUser'])->name('vendors.users.destroy');
});

// La puerta del personal del comercio (ADR-007): su comercio es implícito
// por su usuario — jamás elegido por URL. El middleware rebota a cada
// audiencia a SU puerta.
Route::middleware(['auth', EnsureEventVendorUser::class])->prefix('event-vendor')->name('event-vendor.')->group(function (): void {
    Route::get('/', ComercioHomeController::class)->name('home');
    Route::get('/ventas/{order}', [ComercioSalesController::class, 'show'])->name('sales.show');
    // Cuánto esperó su gente. Sin {event} en la ruta: el comercio mira SUS
    // puestos, y la jornada se elige por ?dia= — no hay nada que un id en la
    // URL pudiera decir aquí que el contexto no diga ya mejor.
    Route::get('/tiempos', ComercioTimingsController::class)->name('timings');
    Route::post('/categorias', [ComercioCatalogController::class, 'storeCategory'])->name('categories.store');
    Route::post('/productos', [ComercioCatalogController::class, 'storeProduct'])->name('products.store');
    Route::post('/productos/{product}', [ComercioCatalogController::class, 'updateProduct'])->name('products.update');
    Route::post('/productos/{product}/receta', [ComercioCatalogController::class, 'storeRecipeItem'])->name('recipe.store');
    Route::post('/productos/{product}/receta/{item}/eliminar', [ComercioCatalogController::class, 'destroyRecipeItem'])->name('recipe.destroy');
    Route::post('/insumos', [ComercioInventoryController::class, 'storeItem'])->name('items.store');
    Route::post('/compras', [ComercioInventoryController::class, 'storePurchase'])->name('purchases.store');
    Route::post('/ajustes-de-stock', [ComercioInventoryController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/mermas', [ComercioInventoryController::class, 'storeWaste'])->name('waste.store');
    Route::post('/traslados', [ComercioInventoryController::class, 'storeTransfer'])->name('transfers.store');
    Route::post('/existencias/{level}/umbral', [ComercioInventoryController::class, 'updateThreshold'])->name('thresholds.update');
});

// La casa del bar o restaurante independiente (ADR-008): la modalidad
// NEGOCIO. Sin comercios dentro — su estructura son sucursales, y su
// catálogo es de la cuenta entera.
//
// A diferencia de /event-panel, la frontera de mundo vive en la PUERTA y no
// repartida por los controladores: EnsureBusinessUser exige cuenta de
// negocio, corta suspensiones y limpia el contexto de comercio.
Route::middleware(['auth', EnsureBusinessUser::class])->prefix('business')->name('business.')->group(function (): void {
    Route::get('/', BusinessHomeController::class)->name('home');

    Route::get('/menu', [BusinessMenuController::class, 'index'])->name('menu');
    Route::post('/categorias', [BusinessMenuController::class, 'storeCategory'])->name('categories.store');
    Route::post('/categorias/{category}', [BusinessMenuController::class, 'updateCategory'])->name('categories.update');
    Route::post('/productos', [BusinessMenuController::class, 'storeProduct'])->name('products.store');
    Route::post('/productos/{product}', [BusinessMenuController::class, 'updateProduct'])->name('products.update');
    Route::post('/productos/{product}/receta', [BusinessMenuController::class, 'storeRecipeItem'])->name('recipe.store');
    Route::post('/productos/{product}/receta/{item}/eliminar', [BusinessMenuController::class, 'destroyRecipeItem'])->name('recipe.destroy');

    Route::get('/inventario', [BusinessInventoryController::class, 'index'])->name('inventory');
    Route::post('/insumos', [BusinessInventoryController::class, 'storeItem'])->name('items.store');
    Route::post('/insumos/{item}', [BusinessInventoryController::class, 'updateItem'])->name('items.update');
    Route::post('/compras', [BusinessInventoryController::class, 'storePurchase'])->name('purchases.store');
    Route::post('/ajustes-de-stock', [BusinessInventoryController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/mermas', [BusinessInventoryController::class, 'storeWaste'])->name('waste.store');
    Route::post('/traslados', [BusinessInventoryController::class, 'storeTransfer'])->name('transfers.store');
    Route::post('/existencias/{level}/umbral', [BusinessInventoryController::class, 'updateThreshold'])->name('thresholds.update');

    Route::get('/ventas', [BusinessSalesController::class, 'index'])->name('sales.index');
    Route::get('/ventas/{order}', [BusinessSalesController::class, 'show'])->name('sales.show');

    Route::get('/caja', [BusinessCashController::class, 'index'])->name('cash.index');

    Route::get('/sucursales', [BranchesController::class, 'index'])->name('branches.index');
    Route::post('/sucursales', [BranchesController::class, 'store'])->name('branches.store');
    Route::post('/sucursales/{branch}', [BranchesController::class, 'update'])->name('branches.update');

    Route::get('/equipo', [TeamController::class, 'index'])->name('team.index');
    Route::post('/equipo', [TeamController::class, 'store'])->name('team.store');
    Route::post('/equipo/{user}', [TeamController::class, 'update'])->name('team.update');
    Route::post('/equipo/{user}/eliminar', [TeamController::class, 'destroy'])->name('team.destroy');

    Route::get('/ajustes', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/ajustes', [SettingsController::class, 'update'])->name('settings.update');
});
