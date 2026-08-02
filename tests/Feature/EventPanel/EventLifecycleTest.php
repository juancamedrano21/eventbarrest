<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Identity\Queries\UserPermissions;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * Lo que solo se podía hacer en el Filament que vamos a retirar: cerrar un
 * evento, editar un puesto, renegociar una comisión, sacar a un comercio y
 * corregir el rol de alguien de su equipo.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);
        $this->puesto = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Puesto Norte', OperatingUnitKind::Kitchen,
        );
    });

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('closes an event, which no screen could do before', function (): void {
    $this->actingAs($this->owner)
        ->post("/event-panel/eventos/{$this->event->id}", [
            'name' => 'Bocao 2026',
            'venue' => 'Puerto de Santo Domingo',
            'starts_at' => $this->event->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $this->event->ends_at->format('Y-m-d\TH:i'),
            'status' => EventStatus::Closed->value,
        ])
        ->assertRedirect();

    expect($this->event->fresh()->status)->toBe(EventStatus::Closed)
        ->and($this->event->fresh()->venue)->toBe('Puerto de Santo Domingo');
});

it('refuses to close an event with an open cash session', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => app(OpenCashSession::class)($this->puesto, null, 100000),
    ));

    $this->actingAs($this->owner)
        ->from("/event-panel/eventos/{$this->event->id}")
        ->post("/event-panel/eventos/{$this->event->id}", [
            'name' => 'Bocao 2026', 'venue' => null,
            'starts_at' => $this->event->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $this->event->ends_at->format('Y-m-d\TH:i'),
            'status' => EventStatus::Closed->value,
        ])
        ->assertSessionHasErrors('status');

    expect($this->event->fresh()->status)->not->toBe(EventStatus::Closed);
});

it('needs the settle permission to liquidate, not just events management', function (): void {
    // Los roles de sistema que administran eventos también liquidan, así que
    // el guard solo se ve con un rol a medida — que es justo lo que el
    // superadmin compone para separar quién administra de quién cierra caja.
    $plantilla = RoleTemplate::query()->create([
        'label' => 'Coordinador',
        'description' => 'Administra el evento, pero no hace el corte financiero.',
        'permissions' => ['events.manage', 'event_outlets.manage'],
    ]);
    $plantilla->forceFill(['name' => 'coordinador', 'kind' => RoleKind::Account->value])->save();

    app(ApplyRoleTemplates::class)();

    $gestor = app(CreateTenantUser::class)(
        $this->organizer, 'Beto', 'beto@x.test', 'Secreta-2026', 'coordinador',
    );

    expect(app(UserPermissions::class)->namesFor($gestor)->contains('events.settle'))->toBeFalse();

    $this->actingAs($gestor)
        ->post("/event-panel/eventos/{$this->event->id}", [
            'name' => 'Bocao 2026', 'venue' => null,
            'starts_at' => $this->event->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $this->event->ends_at->format('Y-m-d\TH:i'),
            'status' => EventStatus::Settled->value,
        ])
        ->assertForbidden();

    expect($this->event->fresh()->status)->not->toBe(EventStatus::Settled);
});

it('renames an outlet and closes it without touching its event', function (): void {
    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/puestos/{$this->puesto->id}", [
            'name' => 'Puesto Norte II', 'kind' => 'bar', 'status' => 'closed',
        ])
        ->assertRedirect();

    $puesto = $this->puesto->fresh();

    expect($puesto->name)->toBe('Puesto Norte II')
        ->and($puesto->status)->toBe(OperatingUnitStatus::Closed)
        ->and($puesto->kind)->toBe(OperatingUnitKind::Bar)
        ->and($puesto->event_id)->toBe($this->event->id)
        ->and($puesto->vendor_id)->toBe($this->vendor->id);
});

it('renegotiates the commission of an existing participation', function (): void {
    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/eventos/{$this->event->id}/comision", [
            'commission' => '12.5',
        ])
        ->assertRedirect();

    $participacion = EventVendor::query()
        ->where('event_id', $this->event->id)
        ->where('vendor_id', $this->vendor->id)
        ->sole();

    expect($participacion->commission_bps)->toBe(1250);
});

it('removes a vendor from an event and closes its outlets instead of deleting them', function (): void {
    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/eventos/{$this->event->id}/retirar")
        ->assertRedirect();

    expect(EventVendor::query()
        ->where('event_id', $this->event->id)
        ->where('vendor_id', $this->vendor->id)
        ->exists())->toBeFalse();

    // El puesto sobrevive cerrado: sus ventas lo referencian para siempre.
    $puesto = EventOutlet::query()->withoutGlobalScopes()->whereKey($this->puesto->id)->sole();

    expect($puesto->status)->toBe(OperatingUnitStatus::Closed);
});

it('refuses to remove a vendor that still has an open cash session', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => app(OpenCashSession::class)($this->puesto, null, 100000),
    ));

    $this->actingAs($this->owner)
        ->from("/event-panel/comercios/{$this->vendor->id}")
        ->post("/event-panel/comercios/{$this->vendor->id}/eventos/{$this->event->id}/retirar")
        ->assertSessionHasErrors('vendor');

    expect(EventVendor::query()
        ->where('event_id', $this->event->id)
        ->where('vendor_id', $this->vendor->id)
        ->exists())->toBeTrue();
});

it('changes the role of vendor staff, which only the old panel could do', function (): void {
    $cajero = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::Cashier, $this->vendor, null, 'lia',
    );

    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/usuarios/{$cajero->id}/rol", [
            'role' => Role::VendorManager->value,
        ])
        ->assertRedirect();

    expect(app(UserPermissions::class)->namesFor($cajero->fresh())->contains('catalog.manage'))->toBeTrue();
});

it('never touches a user from another vendor', function (): void {
    $otro = app(TenantContext::class)->runAs($this->organizer, function () {
        $v = app(CreateVendor::class)('Cervecería');
        app(InviteVendorToEvent::class)($this->event, $v, 500);

        return $v;
    });

    $ajeno = app(CreateTenantUser::class)(
        $this->organizer, 'Zoe', 'zoe@x.test', 'Secreta-2026', Role::Cashier, $otro, null, 'zoe',
    );

    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/usuarios/{$ajeno->id}/rol", [
            'role' => Role::VendorManager->value,
        ])
        ->assertNotFound();

    expect(User::query()->whereKey($ajeno->id)->sole()->vendor_id)->toBe($otro->id);
});

// ───────────────── Lo que la revisión adversarial encontró ─────────────────

it('never enrolls a vendor into an event through the commission endpoint', function (): void {
    // La acción de dominio es crear-o-actualizar: sin este guard, la URL de
    // «renegociar» daba de alta al comercio en un evento al que nadie lo
    // invitó — y lo metía en su liquidación.
    $otroEvento = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(CreateEvent::class)('Bocao 2027', now()->addYear(), now()->addYear()->addWeek()),
    );

    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/eventos/{$otroEvento->id}/comision", [
            'commission' => '50',
        ])
        ->assertNotFound();

    expect(EventVendor::query()
        ->where('event_id', $otroEvento->id)
        ->where('vendor_id', $this->vendor->id)
        ->exists())->toBeFalse();
});

it('asks for the settle permission to UNDO a settlement too', function (): void {
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $this->event->update(['status' => EventStatus::Settled]),
    );

    $plantilla = RoleTemplate::query()->create([
        'label' => 'Coordinador',
        'description' => 'Administra el evento, pero no hace el corte financiero.',
        'permissions' => ['events.manage', 'event_outlets.manage'],
    ]);
    $plantilla->forceFill(['name' => 'coordinador', 'kind' => RoleKind::Account->value])->save();
    app(ApplyRoleTemplates::class)();

    $gestor = app(CreateTenantUser::class)(
        $this->organizer, 'Beto', 'beto@x.test', 'Secreta-2026', 'coordinador',
    );

    // Reabrir un evento liquidado es tan delicado como cerrarlo.
    $this->actingAs($gestor)
        ->post("/event-panel/eventos/{$this->event->id}", [
            'name' => 'Bocao 2026', 'venue' => null,
            'starts_at' => $this->event->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $this->event->ends_at->format('Y-m-d\TH:i'),
            'status' => EventStatus::Active->value,
        ])
        ->assertForbidden();

    expect($this->event->fresh()->status)->toBe(EventStatus::Settled);
});

it('recovers the stock movements the old panel had: count, waste, transfer and threshold', function (): void {
    [$insumo, $otroPuesto] = app(TenantContext::class)->runAs($this->organizer, fn (): array => [
        app(VendorContext::class)->runAs(
            $this->vendor,
            fn () => InventoryItem::create(
                ['name' => 'Tortilla', 'base_unit' => 'unidad', 'cost_cents' => 500],
            ),
        ),
        app(CreateEventOutlet::class)($this->event, $this->vendor, 'Puesto Sur', OperatingUnitKind::Kitchen),
    ]);

    $ruta = "/event-panel/comercios/{$this->vendor->id}";

    $this->actingAs($this->owner)->post("{$ruta}/compras", [
        'operating_unit_id' => $this->puesto->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '100', 'unit_cost' => '5',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("{$ruta}/ajustes-de-stock", [
        'operating_unit_id' => $this->puesto->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '-10', 'reason' => 'Conteo',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("{$ruta}/mermas", [
        'operating_unit_id' => $this->puesto->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '5', 'reason' => 'Se quemaron',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("{$ruta}/traslados", [
        'from_unit_id' => $this->puesto->id, 'to_unit_id' => $otroPuesto->id,
        'inventory_item_id' => $insumo->id, 'quantity' => '25',
    ])->assertRedirect();

    $nivel = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => StockLevel::query()
            ->where('operating_unit_id', $this->puesto->id)
            ->where('inventory_item_id', $insumo->id)
            ->sole(),
    );

    // 100 − 10 − 5 − 25 = 60
    expect((float) $nivel->quantity)->toBe(60.0);

    // Y el umbral, sin el cual el aviso «Bajo mínimo» no puede dispararse.
    $this->actingAs($this->owner)
        ->post("{$ruta}/existencias/{$nivel->id}/umbral", ['alert_threshold' => '20'])
        ->assertRedirect();

    expect((float) $nivel->fresh()->alert_threshold)->toBe(20.0)
        ->and($nivel->fresh()->isLow())->toBeFalse();
});

it('refuses a duplicate name when CREATING, not only when editing', function (): void {
    $ruta = "/event-panel/comercios/{$this->vendor->id}";

    $this->actingAs($this->owner)->post("{$ruta}/categorias", ['name' => 'Comida', 'tipo' => 'alimentos'])->assertRedirect();

    // Antes esto llegaba al índice de la base y salía un 500 en la cara.
    $this->actingAs($this->owner)
        ->from($ruta)
        ->post("{$ruta}/categorias", ['name' => 'Comida', 'tipo' => 'alimentos'])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->owner)->post("{$ruta}/insumos", ['name' => 'Maíz', 'base_unit' => 'g', 'cost' => '1'])->assertRedirect();
    $this->actingAs($this->owner)
        ->from($ruta)
        ->post("{$ruta}/insumos", ['name' => 'Maíz', 'base_unit' => 'g', 'cost' => '1'])
        ->assertSessionHasErrors('name');
});

it('edits and deletes vendor staff, a capability the old panel had', function (): void {
    $cajero = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::Cashier, $this->vendor, null, 'lia',
    );

    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/usuarios/{$cajero->id}/datos", [
            'name' => 'Lia Pérez', 'email' => 'lia@x.test', 'username' => 'liap', 'password' => '',
        ])
        ->assertRedirect();

    expect($cajero->fresh()->name)->toBe('Lia Pérez')
        ->and($cajero->fresh()->username)->toBe('liap');

    $this->actingAs($this->owner)
        ->post("/event-panel/comercios/{$this->vendor->id}/usuarios/{$cajero->id}/eliminar")
        ->assertRedirect();

    expect(User::query()->whereKey($cajero->id)->exists())->toBeFalse();
});

it('gives the organizer a team screen of its own account', function (): void {
    // Se perdió al apagar /app: sin ella, un organizador no podía dar de
    // alta a su propio equipo.
    $deComercio = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    $this->actingAs($this->owner)
        ->get('/event-panel/equipo')
        ->assertOk()
        ->assertSee('ana@x.test')
        // El personal de comercio se administra en el perfil de SU comercio.
        ->assertDontSee($deComercio->email);

    $this->actingAs($this->owner)
        ->post('/event-panel/equipo', [
            'name' => 'Eva', 'email' => 'eva@x.test', 'username' => null,
            'password' => 'Secreta-2026', 'role' => Role::EventManager->value,
        ])
        ->assertRedirect();

    $eva = User::query()->where('email', 'eva@x.test')->sole();

    expect($eva->tenant_id)->toBe($this->organizer->id)
        ->and($eva->vendor_id)->toBeNull();

    // Y no alcanza al personal de comercio ni por id.
    $this->actingAs($this->owner)
        ->post("/event-panel/equipo/{$deComercio->id}", [
            'name' => 'Robada', 'email' => 'caro@x.test',
            'password' => '', 'role' => Role::Owner->value,
        ])
        ->assertNotFound();
});
