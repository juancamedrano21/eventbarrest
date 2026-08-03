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
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\AdvanceKitchenTicket;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * La puerta de la tablet, probada por donde de verdad duele.
 *
 * Los tres primeros casos son la portería: quién entra y quién no. El cuarto
 * es el que justifica que este middleware exista en vez de reaprovechar el
 * del POS — dos comercios del MISMO festival, y la tablet de uno pidiendo el
 * tablero. Si el contexto se fijara mal, la respuesta seguiría siendo 200 y
 * el fallo se descubriría en la cocina, no aquí.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );

        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->evento, $this->tacos, 1000);
        $this->puestoTacos = outletFor($this->evento, 'Puesto Norte', OperatingUnitKind::Kitchen, $this->tacos);
        $this->pinTacos = app(RotateOutletKdsPin::class)($this->puestoTacos);

        $this->pizzas = app(CreateVendor::class)('Pizzas Doña Ana');
        app(InviteVendorToEvent::class)($this->evento, $this->pizzas, 1000);
        $this->puestoPizzas = outletFor($this->evento, 'Puesto Sur', OperatingUnitKind::Kitchen, $this->pizzas);
        $this->pinPizzas = app(RotateOutletKdsPin::class)($this->puestoPizzas);
    });

    // El alta pasa por la puerta de verdad: sin cuenta activa, como una
    // tablet recién sacada de la caja.
    $this->tabletTacos = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinTacos, 'Tablet norte', null,
    );
    $this->tabletPizzas = app(EnrollKdsDevice::class)(
        (string) $this->pizzas->kds_code, $this->pinPizzas, 'Tablet sur', null,
    );

    rutaDeSondeoDelKds();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Una ruta mínima detrás del alias, que devuelve lo que el middleware dejó
 * puesto. Se sondea el CONTEXTO, no un endpoint concreto: lo que se prueba
 * es la puerta, y los controladores del KDS todavía no existen.
 */
function rutaDeSondeoDelKds(): void
{
    Route::middleware('kds.device')->get('/api/_pruebas/kds', function (Request $request): JsonResponse {
        $device = $request->attributes->get('kds_device');

        return response()->json([
            'tenant_id' => app(TenantContext::class)->id(),
            'vendor_id' => app(VendorContext::class)->id(),
            'device_id' => $device instanceof KdsDevice ? $device->id : null,
            'equipo_de_permisos' => getPermissionsTeamId(),
            // La consulta va SIN filtros a mano: lo que no salga aquí es
            // porque los scopes no lo dejan salir.
            'comandas' => KitchenTicket::query()->orderBy('id')->pluck('id')->all(),
        ]);
    });
}

/** Lo que hace la tablet en cada polling: el token en la cabecera y nada más. */
function sondearElKds(string $token): TestResponse
{
    return test()->withToken($token)->getJson('/api/_pruebas/kds');
}

/** Una venta cobrada del comercio, ya empezada en cocina: la comanda que se ve o no se ve. */
function comandaEmpezadaDe(Vendor $vendor, EventOutlet $puesto): KitchenTicket
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn (): KitchenTicket => app(VendorContext::class)->runAs($vendor, function () use ($vendor, $puesto): KitchenTicket {
            $categoria = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $producto = Product::create([
                'category_id' => $categoria->id,
                'name' => 'Plato de '.$vendor->name,
                'type' => ProductType::Simple,
                'price_cents' => 25000,
            ]);

            $caja = app(OpenCashSession::class)($puesto, null, 0);

            $orden = app(PlaceOrder::class)(
                $caja,
                [['product_id' => $producto->id, 'quantity' => 1]],
                'pos-'.$vendor->id,
            );

            $orden = app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);

            return app(AdvanceKitchenTicket::class)(
                $orden, DispatchArea::Kitchen, KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
            );
        }),
    );
}

it('sets the account and the vendor from the device token alone', function (): void {
    $respuesta = sondearElKds($this->tabletTacos->plainToken);

    $respuesta->assertOk()
        ->assertJsonPath('tenant_id', $this->organizer->id)
        ->assertJsonPath('vendor_id', $this->tacos->id)
        ->assertJsonPath('device_id', $this->tabletTacos->device->id);

    // Un dispositivo no tiene roles, así que tampoco equipo de permisos:
    // cualquier ->can() que se cuele en un controlador del KDS dirá que no.
    $respuesta->assertJsonPath('equipo_de_permisos', null);

    // Y queda el «sigo viva» que pinta el punto verde en el panel.
    $this->tabletTacos->device->refresh();
    expect($this->tabletTacos->device->last_seen_at)->not->toBeNull();
});

it('refuses a request with no token at all', function (): void {
    $this->getJson('/api/_pruebas/kds')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_sin_token');
});

it('refuses a revoked tablet on the very next poll', function (): void {
    // Entraba hace un segundo: revocar no es una configuración que se
    // aplique al reiniciar, es apagar la pantalla ahora.
    sondearElKds($this->tabletTacos->plainToken)->assertOk();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(RevokeKdsDevice::class)($this->tabletTacos->device);
    });

    sondearElKds($this->tabletTacos->plainToken)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_revocado');
});

it('refuses a tablet whose outlet was closed', function (): void {
    // Es lo que deja RemoveVendorFromEvent al sacar a un comercio del
    // evento: el puesto cerrado y el token de la tablet intacto. Revalidar
    // en CADA petición es lo único que apaga esa pantalla.
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->puestoTacos->update(['status' => OperatingUnitStatus::Closed]);
    });

    sondearElKds($this->tabletTacos->plainToken)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_revocado');
});

it('never shows a competitor tickets from the same festival', function (): void {
    $mia = comandaEmpezadaDe($this->tacos, $this->puestoTacos);
    $delVecino = comandaEmpezadaDe($this->pizzas, $this->puestoPizzas);

    // Misma cuenta, mismo evento, dos comercios: TenantScope no separa nada
    // aquí — lo único que los separa es el comercio que fija forDevice.
    expect($mia->tenant_id)->toBe($delVecino->tenant_id);
    expect($mia->vendor_id)->not->toBe($delVecino->vendor_id);

    sondearElKds($this->tabletTacos->plainToken)
        ->assertOk()
        ->assertJsonPath('comandas', [$mia->id]);

    // Y simétrico, para que el test no pase por casualidad del orden de ids.
    sondearElKds($this->tabletPizzas->plainToken)
        ->assertOk()
        ->assertJsonPath('comandas', [$delVecino->id]);
});
