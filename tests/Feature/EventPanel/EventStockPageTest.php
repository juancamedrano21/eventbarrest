<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Inventory\Actions\AllocateToEvent;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * La pantalla de mercancía del evento: entregar, devolver y ver qué falta.
 *
 * El organizador escribe en el inventario de OTRO —el comercio dueño del
 * puesto—, así que estas pruebas vigilan sobre todo la frontera: que no se
 * cruce mercancía entre comercios y que el contexto se abra solo para lo justo.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now());
        $this->vendor = app(CreateVendor::class)('Cervecería del Malecón');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);

        $this->barra = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Barra Norte', OperatingUnitKind::Bar,
        );
        $this->bodega = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Bodega', OperatingUnitKind::Mixed,
        );

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $this->cerveza = InventoryItem::create([
                'name' => 'Presidente 350ml', 'base_unit' => 'unidad', 'cost_cents' => 8000,
            ]);
        });
    });

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    $this->ruta = "/event-panel/eventos/{$this->event->id}/mercancia";
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('hands stock to an outlet from the organizer screen', function (): void {
    $this->actingAs($this->owner)
        ->post("{$this->ruta}/entregar", [
            'outlet_id' => $this->barra->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '240',
        ])
        ->assertRedirect();

    $nivel = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => StockLevel::query()->where('operating_unit_id', $this->barra->id)->sole(),
    ));

    expect((float) $nivel->quantity)->toBe(240.0);
});

it('takes it out of the warehouse when the form names one', function (): void {
    $this->actingAs($this->owner)->post("{$this->ruta}/entregar", [
        'outlet_id' => $this->bodega->id,
        'inventory_item_id' => $this->cerveza->id,
        'quantity' => '500',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("{$this->ruta}/entregar", [
        'outlet_id' => $this->barra->id,
        'inventory_item_id' => $this->cerveza->id,
        'quantity' => '240',
        'counterpart_id' => $this->bodega->id,
    ])->assertRedirect();

    [$enBodega, $enBarra] = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn (): array => [
            (float) StockLevel::query()->where('operating_unit_id', $this->bodega->id)->sole()->quantity,
            (float) StockLevel::query()->where('operating_unit_id', $this->barra->id)->sole()->quantity,
        ],
    ));

    expect($enBodega)->toBe(260.0)->and($enBarra)->toBe(240.0);
});

it('gives back what did not get sold', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => app(AllocateToEvent::class)($this->barra, $this->cerveza, 240),
    ));

    $this->actingAs($this->owner)
        ->post("{$this->ruta}/devolver", [
            'outlet_id' => $this->barra->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '40',
        ])
        ->assertRedirect();

    $movimiento = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => StockMovement::query()
            ->where('operating_unit_id', $this->barra->id)
            ->where('type', StockMovementType::EventReturn)
            ->sole(),
    ));

    expect((float) $movimiento->quantity)->toBe(-40.0);
});

it('shows the gap nobody can explain', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        function (): void {
            app(AllocateToEvent::class)($this->barra, $this->cerveza, 240);
            app(RegisterWaste::class)($this->barra, $this->cerveza, 12, 'Se rompieron en la nevera');
        },
    ));

    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('Presidente 350ml')
        ->assertSee('Barra Norte')
        // 240 entregadas − 12 rotas = 228 sin explicar.
        ->assertSee('228')
        ->assertSee('línea no cuadra');
});

it('never lets one vendor outlet feed another vendor', function (): void {
    $ajeno = app(TenantContext::class)->runAs($this->organizer, function () {
        $otro = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $otro, 1200);

        return app(CreateEventOutlet::class)(
            $this->event, $otro, 'Puesto Tacos', OperatingUnitKind::Kitchen,
        );
    });

    // El insumo es de la cervecería: entregarlo a un puesto de otro comercio
    // le regalaría mercancía ajena.
    $this->actingAs($this->owner)
        ->from($this->ruta)
        ->post("{$this->ruta}/entregar", [
            'outlet_id' => $ajeno->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '10',
        ])
        ->assertNotFound();

    expect(app(TenantContext::class)->runAs($this->organizer, fn () => StockMovement::query()
        ->withoutGlobalScopes()
        ->where('operating_unit_id', $ajeno->id)
        ->exists()))->toBeFalse();
});

it('never touches an outlet of another event', function (): void {
    $otroPuesto = app(TenantContext::class)->runAs($this->organizer, function () {
        $otroEvento = app(CreateEvent::class)('Bocao 2027', now()->addYear(), now()->addYear()->addDay());
        app(InviteVendorToEvent::class)($otroEvento, $this->vendor, 1000);

        return app(CreateEventOutlet::class)(
            $otroEvento, $this->vendor, 'Barra 2027', OperatingUnitKind::Bar,
        );
    });

    $this->actingAs($this->owner)
        ->from($this->ruta)
        ->post("{$this->ruta}/entregar", [
            'outlet_id' => $otroPuesto->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '10',
        ])
        ->assertNotFound();
});

it('refuses an amount of zero with a message, not a 500', function (): void {
    $this->actingAs($this->owner)
        ->from($this->ruta)
        ->post("{$this->ruta}/entregar", [
            'outlet_id' => $this->barra->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '0',
        ])
        ->assertSessionHasErrors('quantity');
});

it('lets someone read the report without letting them move stock', function (): void {
    $plantilla = RoleTemplate::query()->create([
        'label' => 'Coordinador',
        'description' => 'Mira el evento, pero no mueve mercancía.',
        'permissions' => ['events.manage'],
    ]);
    $plantilla->forceFill(['name' => 'coordinador', 'kind' => RoleKind::Account->value])->save();
    app(ApplyRoleTemplates::class)();

    $gestor = app(CreateTenantUser::class)(
        $this->organizer, 'Beto', 'beto@x.test', 'Secreta-2026', 'coordinador',
    );

    $this->actingAs($gestor)->get($this->ruta)->assertOk()->assertDontSee('Entregar mercancía');

    $this->actingAs($gestor)
        ->post("{$this->ruta}/entregar", [
            'outlet_id' => $this->barra->id,
            'inventory_item_id' => $this->cerveza->id,
            'quantity' => '10',
        ])
        ->assertForbidden();
});

it('keeps vendor staff out of the organizer screen', function (): void {
    $cajero = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor, null, 'lia',
    );

    $this->actingAs($cajero)->get($this->ruta)->assertForbidden();
});
