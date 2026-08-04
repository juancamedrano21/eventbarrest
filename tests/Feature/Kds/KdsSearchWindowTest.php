<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;

/**
 * Qué trozo de la noche mira el «¿y lo mío?».
 *
 * La búsqueda tiene que CONTENER siempre a la ventana del tablero. No es una
 * elegancia: si el tablero enseña una tarjeta que la búsqueda no encuentra,
 * la cocinera está viendo la comanda en la pantalla mientras le contesta al
 * cliente que esa venta no existe, y eso es exactamente lo que esta pantalla
 * se construyó para no tener que decir.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );

        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($evento, $this->vendor, 1000);
        $this->puesto = outletFor($evento, 'Puesto Norte', OperatingUnitKind::Mixed, $this->vendor);
        $this->pin = app(RotateOutletKdsPin::class)($this->puesto);

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $this->taco = Product::create([
                'category_id' => $cocina->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
        });
    });

    $this->tablet = app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, $this->pin, 'Tablet norte', null,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('still finds at midnight the very card the board is still showing', function (): void {
    $medianoche = today((string) config('app.business_timezone'));

    // Dos horas antes de que el día de calendario se reinicie. En un
    // festival eso no es el final de nada: es la cola más larga de la noche.
    $this->travelTo($medianoche->copy()->subHours(2));

    $orden = app(TenantContext::class)->runAs($this->organizer, fn (): Order => app(VendorContext::class)->runAs(
        $this->vendor,
        function (): Order {
            $caja = app(OpenCashSession::class)($this->puesto, null, 0);

            assert($caja instanceof CashSession);

            $orden = app(PlaceOrder::class)(
                $caja,
                [['product_id' => $this->taco->id, 'quantity' => 2]],
                'kds-0001',
                customerName: 'Marielys',
            );

            return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
        },
    ));

    // Y ahora son las 00:10 del puesto. El día local acaba de empezar y la
    // gente de las dos de la mañana sigue esperando su plato.
    $this->travelTo($medianoche->copy()->addMinutes(10));

    $token = $this->tablet->plainToken;

    // El tablero sigue enseñando la tarjeta, porque su ventana son doce
    // horas RODANTES y no un día de calendario.
    expect(collect($this->withToken($token)->getJson('/api/kds/comandas')->assertOk()->json('tickets'))
        ->pluck('order_id')->all())->toBe([$orden->id]);

    // Y la búsqueda tiene que decir lo mismo. Miraba el día local a secas,
    // así que a las 00:00 se vaciaba de golpe: la tarjeta en la pantalla y
    // la búsqueda jurando que esa venta no existe, a la hora punta.
    $this->withToken($token)->getJson('/api/kds/buscar?q=Marielys')
        ->assertOk()
        ->assertJsonPath('results.0.order_id', $orden->id)
        ->assertJsonPath('results.0.areas.0.items_count', 2);

    // Por el número cantado también, que es lo otro que trae el cliente.
    $this->withToken($token)->getJson('/api/kds/buscar?q='.urlencode((string) $orden->publicNumber()))
        ->assertOk()
        ->assertJsonPath('results.0.order_id', $orden->id);
});
