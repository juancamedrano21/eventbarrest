<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * A nombre de quién va la orden: lo que el cajero grita cuando el pedido
 * sale y lo que lleva impreso la comanda. Se congela con la venta.
 */
beforeEach(function (): void {
    $this->negocio = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        $this->sucursal = app(CreateBranch::class)('Sucursal Centro');
        $categoria = Category::query()->create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);
        $this->producto = Product::query()->create([
            'category_id' => $categoria->id, 'name' => 'Presidente',
            'type' => ProductType::Simple, 'price_cents' => 20000, 'itbis_exempt' => true,
        ]);
        $this->caja = app(OpenCashSession::class)($this->sucursal, null, 0);
    });

    $this->cajero = app(CreateTenantUser::class)(
        $this->negocio, 'Luis', 'luis@bar.test', 'Secreta-2026', Role::Cashier, null, null, 'luis',
    );

    Sanctum::actingAs($this->cajero, ['pos']);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** @param  array<string, mixed>  $extra */
function cobrarConNombre(array $extra = []): array
{
    return test()->postJson('/api/pos/orders', [
        'cash_session_id' => test()->caja->id,
        'client_ref' => 'pos-'.bin2hex(random_bytes(4)),
        'lines' => [['product_id' => test()->producto->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 20000],
        ...$extra,
    ])->json();
}

it('keeps the customer name with the sale and hands it back', function (): void {
    $respuesta = cobrarConNombre(['customer_name' => 'Mesa 4 — Ana']);

    expect($respuesta['customer_name'])->toBe('Mesa 4 — Ana');

    $orden = Order::query()->withoutGlobalScopes()->whereKey($respuesta['id'])->sole();

    expect($orden->customer_name)->toBe('Mesa 4 — Ana');
});

it('treats a blank name as no name at all', function (): void {
    $respuesta = cobrarConNombre(['customer_name' => '   ']);

    // Una cadena en blanco imprimiría una línea sola en la comanda.
    expect($respuesta['customer_name'])->toBeNull();
});

it('works without a name, which is the usual case at a busy bar', function (): void {
    $respuesta = cobrarConNombre();

    expect($respuesta['customer_name'])->toBeNull()
        ->and($respuesta['status'])->toBe('paid');
});

it('refuses a name longer than the ticket can print', function (): void {
    $this->postJson('/api/pos/orders', [
        'cash_session_id' => $this->caja->id,
        'client_ref' => 'pos-largo',
        'customer_name' => str_repeat('a', 61),
        'lines' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 20000],
    ])->assertStatus(422)->assertJsonValidationErrors('customer_name');
});

it('gives the shift list what a reprint needs: name, lines and the tax breakdown', function (): void {
    cobrarConNombre(['customer_name' => 'Pedro']);

    $orden = $this->getJson("/api/pos/sales?cash_session_id={$this->caja->id}")
        ->assertOk()
        ->json('orders.0');

    // Sin las líneas, reimprimir desde el listado exigiría una segunda
    // petición — y el cajero que reimprime está en medio de un turno.
    expect($orden['customer_name'])->toBe('Pedro')
        ->and($orden['lines'])->toHaveCount(1)
        ->and($orden['lines'][0]['product_name'])->toBe('Presidente')
        ->and($orden['subtotal_cents'])->toBe(20000)
        ->and($orden)->toHaveKeys(['itbis_cents', 'tip_cents', 'number']);
});

it('never lets the name be edited once the sale is charged', function (): void {
    $respuesta = cobrarConNombre(['customer_name' => 'Ana']);

    app(TenantContext::class)->runAs($this->negocio, function () use ($respuesta): void {
        $orden = Order::query()->whereKey($respuesta['id'])->sole();

        // Una venta cobrada es historia, también en este campo.
        expect(fn () => $orden->update(['customer_name' => 'Otro']))
            ->toThrow(Exception::class);
    });
});
