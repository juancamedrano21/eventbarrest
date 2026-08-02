<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Business\Models\Branch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateVendor;
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
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * La puerta /business (ADR-008): la casa del bar independiente. Su mundo se
 * reconoce por lo que ES —una cuenta de negocio—, su catálogo es de la
 * cuenta entera y sus existencias van por sucursal.
 */
beforeEach(function (): void {
    $this->negocio = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        $this->sucursal = app(CreateBranch::class)('Sucursal Centro');
    });

    $this->dueno = app(CreateTenantUser::class)(
        $this->negocio, 'Juan', 'juan@bar.test', 'Secreta-2026', Role::Owner,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

// ───────────────────────── La puerta ─────────────────────────

it('sends the owner of a business account to its own door, not the organizer panel', function (): void {
    $this->actingAs($this->dueno)->get('/')->assertRedirect('/business');
});

it('opens the business home', function (): void {
    $this->actingAs($this->dueno)
        ->get('/business')
        ->assertOk()
        ->assertSee('Bar del Puerto')
        ->assertSee('Ventas de hoy');
});

it('bounces an organizer account away from the business door', function (): void {
    $organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    $usuario = app(CreateTenantUser::class)(
        $organizador, 'Ana', 'ana@fest.test', 'Secreta-2026', Role::Owner,
    );

    $this->actingAs($usuario)->get('/business')->assertRedirect('/event-panel');
});

it('sends a cashier of a bar to the pos, not to a dead end', function (): void {
    $cajero = app(CreateTenantUser::class)(
        $this->negocio, 'Luis', 'luis@bar.test', 'Secreta-2026', Role::Cashier, null, null, 'luis',
    );

    // Antes caía en /event-panel, un panel de organizador donde su rol no
    // tiene ni una pantalla.
    $this->actingAs($cajero)->get('/')->assertRedirect('/pos');
    $this->actingAs($cajero)->get('/business')->assertRedirect('/pos');
});

it('cuts access when the account is suspended', function (): void {
    $this->negocio->update(['status' => TenantStatus::Suspended]);

    $this->actingAs($this->dueno)->get('/business')->assertForbidden();
});

it('turns guests away to the login', function (): void {
    $this->get('/business')->assertRedirect('/entrar');
});

// ───────────────────────── Sucursales ─────────────────────────

it('creates and edits a branch', function (): void {
    $this->actingAs($this->dueno)
        ->post('/business/sucursales', ['name' => 'Sucursal Malecón', 'kind' => 'bar'])
        ->assertRedirect();

    $nueva = Branch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->negocio->id)->where('name', 'Sucursal Malecón')->sole();

    expect($nueva->event_id)->toBeNull()
        ->and($nueva->vendor_id)->toBeNull()
        ->and($nueva->kind)->toBe(OperatingUnitKind::Bar);

    $this->actingAs($this->dueno)
        ->post("/business/sucursales/{$nueva->id}", [
            'name' => 'Sucursal Malecón II', 'kind' => 'mixed', 'status' => 'closed',
        ])
        ->assertRedirect();

    expect($nueva->fresh()->name)->toBe('Sucursal Malecón II')
        ->and($nueva->fresh()->status)->toBe(OperatingUnitStatus::Closed);
});

it('refuses a second branch with the same name', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/sucursales')
        ->post('/business/sucursales', ['name' => 'Sucursal Centro', 'kind' => 'mixed'])
        ->assertSessionHasErrors('name');
});

it('never shows an event outlet among the branches', function (): void {
    // Un puesto de evento de OTRA cuenta jamás debe colarse aquí.
    $organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    app(TenantContext::class)->runAs($organizador, function (): void {
        app(CreateVendor::class)('Tacos del Puerto');
    });

    $this->actingAs($this->dueno)
        ->get('/business/sucursales')
        ->assertOk()
        ->assertSee('Sucursal Centro')
        ->assertDontSee('Tacos del Puerto');
});

// ───────────────────────── Menú ─────────────────────────

it('builds the menu with categories and products that belong to no vendor', function (): void {
    $this->actingAs($this->dueno)
        ->post('/business/categorias', ['name' => 'Cervezas', 'tipo' => 'bebidas'])
        ->assertRedirect();

    $categoria = Category::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->negocio->id)->sole();

    expect($categoria->vendor_id)->toBeNull()
        ->and($categoria->dispatch)->toBe(DispatchArea::Bar);

    $this->actingAs($this->dueno)
        ->post('/business/productos', [
            'name' => 'Presidente', 'price' => '250.00',
            'category_id' => $categoria->id, 'kind' => 'simple', 'itbis' => 'gravado',
        ])
        ->assertRedirect();

    $producto = Product::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->negocio->id)->sole();

    expect($producto->vendor_id)->toBeNull()
        ->and($producto->price_cents)->toBe(25000)
        ->and($producto->itbis_exempt)->toBeFalse();

    $this->actingAs($this->dueno)->get('/business/menu')->assertOk()->assertSee('Presidente');
});

it('edits a product without wiping what the form does not show', function (): void {
    [$categoria, $producto] = app(TenantContext::class)->runAs($this->negocio, function (): array {
        $c = Category::create(['name' => 'Cervezas', 'dispatch' => DispatchArea::Bar]);

        return [$c, Product::create([
            'category_id' => $c->id, 'name' => 'Presidente', 'type' => ProductType::Simple,
            'price_cents' => 25000, 'itbis_exempt' => true, 'active' => true,
        ])];
    });

    // Solo llega el precio: la exención fiscal no debe perderse.
    $this->actingAs($this->dueno)
        ->post("/business/productos/{$producto->id}", ['price' => '300.00'])
        ->assertRedirect();

    expect($producto->fresh()->price_cents)->toBe(30000)
        ->and($producto->fresh()->itbis_exempt)->toBeTrue()
        ->and($producto->fresh()->category_id)->toBe($categoria->id);
});

it('renames a category and changes where it is dispatched from', function (): void {
    $categoria = app(TenantContext::class)->runAs(
        $this->negocio,
        fn () => Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Bar]),
    );

    $this->actingAs($this->dueno)
        ->post("/business/categorias/{$categoria->id}", ['name' => 'Platos', 'tipo' => 'alimentos'])
        ->assertRedirect();

    expect($categoria->fresh()->name)->toBe('Platos')
        ->and($categoria->fresh()->dispatch)->toBe(DispatchArea::Kitchen);
});

// ───────────────────────── Inventario ─────────────────────────

it('registers a purchase and moves the stock of that branch', function (): void {
    $insumo = app(TenantContext::class)->runAs(
        $this->negocio,
        fn () => InventoryItem::create(['name' => 'Cerveza 350ml', 'base_unit' => 'unidad', 'cost_cents' => 8000]),
    );

    $this->actingAs($this->dueno)
        ->post('/business/compras', [
            'operating_unit_id' => $this->sucursal->id,
            'inventory_item_id' => $insumo->id,
            'quantity' => '24', 'unit_cost' => '85.00', 'reference' => 'Factura 001',
        ])
        ->assertRedirect();

    $this->actingAs($this->dueno)
        ->get('/business/inventario')
        ->assertOk()
        ->assertSee('Cerveza 350ml')
        ->assertSee('Factura 001');
});

it('records a count adjustment, a waste and a transfer between branches', function (): void {
    [$insumo, $otra] = app(TenantContext::class)->runAs($this->negocio, fn (): array => [
        InventoryItem::create(['name' => 'Ron', 'base_unit' => 'ml', 'cost_cents' => 5]),
        app(CreateBranch::class)('Sucursal Malecón'),
    ]);

    $this->actingAs($this->dueno)->post('/business/compras', [
        'operating_unit_id' => $this->sucursal->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '3000', 'unit_cost' => '0.05',
    ])->assertRedirect();

    $this->actingAs($this->dueno)->post('/business/ajustes-de-stock', [
        'operating_unit_id' => $this->sucursal->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '-100', 'reason' => 'Conteo del lunes',
    ])->assertRedirect();

    $this->actingAs($this->dueno)->post('/business/mermas', [
        'operating_unit_id' => $this->sucursal->id, 'inventory_item_id' => $insumo->id,
        'quantity' => '150', 'reason' => 'Botella rota',
    ])->assertRedirect();

    $this->actingAs($this->dueno)->post('/business/traslados', [
        'from_unit_id' => $this->sucursal->id, 'to_unit_id' => $otra->id,
        'inventory_item_id' => $insumo->id, 'quantity' => '500',
    ])->assertRedirect();

    // 3000 − 100 − 150 − 500 = 2250 en la de origen, 500 en la otra.
    app(TenantContext::class)->runAs($this->negocio, function () use ($insumo, $otra): void {
        $nivel = StockLevel::query()
            ->where('operating_unit_id', $this->sucursal->id)
            ->where('inventory_item_id', $insumo->id)->sole();

        $destino = StockLevel::query()
            ->where('operating_unit_id', $otra->id)
            ->where('inventory_item_id', $insumo->id)->sole();

        expect((float) $nivel->quantity)->toBe(2250.0)
            ->and((float) $destino->quantity)->toBe(500.0);
    });
});

it('refuses a transfer to the same branch', function (): void {
    $insumo = app(TenantContext::class)->runAs(
        $this->negocio,
        fn () => InventoryItem::create(['name' => 'Ron', 'base_unit' => 'ml', 'cost_cents' => 5]),
    );

    $this->actingAs($this->dueno)
        ->from('/business/inventario')
        ->post('/business/traslados', [
            'from_unit_id' => $this->sucursal->id, 'to_unit_id' => $this->sucursal->id,
            'inventory_item_id' => $insumo->id, 'quantity' => '10',
        ])
        ->assertSessionHasErrors('to_unit_id');
});

// ───────────────────────── Ajustes ─────────────────────────

it('lets the owner set the account itbis rule, which only the superadmin could touch', function (): void {
    expect($this->negocio->fresh()->itbis_mode)->toBe(ItbisMode::Included);

    $this->actingAs($this->dueno)
        ->post('/business/ajustes', [
            'name' => 'Bar del Puerto', 'rnc' => '', 'itbis_mode' => 'added',
        ])
        ->assertRedirect();

    expect($this->negocio->fresh()->itbis_mode)->toBe(ItbisMode::Added);
});

// ───────────────────────── Equipo ─────────────────────────

it('adds a teammate and never offers roles from the events world', function (): void {
    $this->actingAs($this->dueno)
        ->get('/business/equipo')
        ->assertOk()
        ->assertSee('Juan')
        // Un bar no tiene eventos ni comercios invitados.
        ->assertDontSee('Gerente de eventos')
        ->assertDontSee('Encargado de comercio');

    $this->actingAs($this->dueno)
        ->post('/business/equipo', [
            'name' => 'Marta', 'email' => 'marta@bar.test', 'username' => 'marta',
            'password' => 'Secreta-2026', 'role' => Role::UnitManager->value,
        ])
        ->assertRedirect();

    $marta = User::query()->where('email', 'marta@bar.test')->sole();

    expect($marta->tenant_id)->toBe($this->negocio->id)
        ->and($marta->vendor_id)->toBeNull();
});

it('refuses a duplicate email and a weak password', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/equipo')
        ->post('/business/equipo', [
            'name' => 'Otro Juan', 'email' => 'juan@bar.test',
            'password' => 'Secreta-2026', 'role' => Role::UnitManager->value,
        ])
        ->assertSessionHasErrors('email');

    $this->actingAs($this->dueno)
        ->from('/business/equipo')
        ->post('/business/equipo', [
            'name' => 'Marta', 'email' => 'marta@bar.test',
            'password' => 'corta', 'role' => Role::UnitManager->value,
        ])
        ->assertSessionHasErrors('password');
});

it('keeps the password when the field is left empty on edit', function (): void {
    $marta = app(CreateTenantUser::class)(
        $this->negocio, 'Marta', 'marta@bar.test', 'Secreta-2026', Role::UnitManager,
    );
    $antes = $marta->password;

    $this->actingAs($this->dueno)
        ->post("/business/equipo/{$marta->id}", [
            'name' => 'Marta Pérez', 'email' => 'marta@bar.test',
            'password' => '', 'role' => Role::Warehouse->value,
        ])
        ->assertRedirect();

    expect($marta->fresh()->password)->toBe($antes)
        ->and($marta->fresh()->name)->toBe('Marta Pérez');
});

it('allows demoting an owner once another one exists', function (): void {
    $segunda = app(CreateTenantUser::class)(
        $this->negocio, 'Sara', 'sara@bar.test', 'Secreta-2026', Role::Owner,
    );

    $this->actingAs($this->dueno)
        ->post("/business/equipo/{$segunda->id}", [
            'name' => 'Sara', 'email' => 'sara@bar.test',
            'password' => '', 'role' => Role::UnitManager->value,
        ])
        ->assertRedirect();

    expect(app(UserPermissions::class)->namesFor($segunda->fresh())->contains('users.manage'))->toBeFalse();
});

it('never lets the last owner be deleted or demoted', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/equipo')
        ->post("/business/equipo/{$this->dueno->id}/eliminar")
        ->assertSessionHasErrors('user');

    expect(User::query()->whereKey($this->dueno->id)->exists())->toBeTrue();
});

// ───────────────────────── Permisos ─────────────────────────

it('keeps a warehouse role out of the money and out of the branches', function (): void {
    $almacen = app(CreateTenantUser::class)(
        $this->negocio, 'Pedro', 'pedro@bar.test', 'Secreta-2026', Role::Warehouse,
    );

    // Entra: gestiona inventario. Pero ni ventas ni sucursales ni equipo.
    $this->actingAs($almacen)->get('/business/inventario')->assertOk();
    $this->actingAs($almacen)->get('/business/sucursales')->assertForbidden();
    $this->actingAs($almacen)->get('/business/equipo')->assertForbidden();
    $this->actingAs($almacen)->get('/business/ventas')->assertForbidden();
    $this->actingAs($almacen)->get('/business/ajustes')->assertForbidden();
});

// ───────────────────────── Aislamiento ─────────────────────────

it('does not reach another account data, not even by id', function (): void {
    $otro = app(CreateTenant::class)('Otro Bar', null, TenantType::Business);
    $ajena = app(TenantContext::class)->runAs($otro, fn () => app(CreateBranch::class)('Sucursal Ajena'));

    $this->actingAs($this->dueno)
        ->post("/business/sucursales/{$ajena->id}", ['name' => 'Robada', 'kind' => 'bar', 'status' => 'active'])
        ->assertNotFound();

    expect($ajena->fresh()->name)->toBe('Sucursal Ajena');
});

// ───────────────── Lo que la revisión adversarial encontró ─────────────────

it('never silently promotes someone whose role is no longer offered', function (): void {
    // Un usuario con un rol del mundo eventos heredado: su rol no está entre
    // los ofrecidos, así que ninguna opción del desplegable quedaba marcada
    // y el navegador enviaba la PRIMERA de la lista — «Administrador».
    $eva = app(CreateTenantUser::class)(
        $this->negocio, 'Eva', 'eva@bar.test', 'Secreta-2026', Role::EventManager,
    );

    $html = $this->actingAs($this->dueno)->get('/business/equipo')->assertOk()->getContent();

    // Su rol vigente aparece y sale seleccionado.
    expect($html)->toContain('value="event_manager" selected');

    // Y guardar sin tocar el rol lo respeta, en vez de ascenderla.
    $this->actingAs($this->dueno)
        ->post("/business/equipo/{$eva->id}", [
            'name' => 'Eva Ramírez', 'email' => 'eva@bar.test',
            'password' => '', 'role' => Role::EventManager->value,
        ])
        ->assertRedirect();

    expect(app(UserPermissions::class)->namesFor($eva->fresh())->contains('users.manage'))->toBeFalse()
        ->and($eva->fresh()->name)->toBe('Eva Ramírez');
});

it('explains that the last owner cannot be demoted instead of crashing', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/equipo')
        ->post("/business/equipo/{$this->dueno->id}", [
            'name' => 'Juan', 'email' => 'juan@bar.test',
            'password' => '', 'role' => Role::Warehouse->value,
        ])
        ->assertSessionHasErrors('role');

    expect(app(UserPermissions::class)->namesFor($this->dueno->fresh())->contains('users.manage'))->toBeTrue();
});

it('404s a recipe ingredient from another account instead of erroring', function (): void {
    $receta = app(TenantContext::class)->runAs($this->negocio, function () {
        $c = Category::create(['name' => 'Cócteles', 'dispatch' => DispatchArea::Bar]);

        return Product::create([
            'category_id' => $c->id, 'name' => 'Mojito',
            'type' => ProductType::Recipe, 'price_cents' => 45000,
        ]);
    });

    $otro = app(CreateTenant::class)('Otro Bar', null, TenantType::Business);
    $ajeno = app(TenantContext::class)->runAs(
        $otro,
        fn () => InventoryItem::create(['name' => 'Ron ajeno', 'base_unit' => 'ml', 'cost_cents' => 5]),
    );

    $this->actingAs($this->dueno)
        ->post("/business/productos/{$receta->id}/receta", [
            'inventory_item_id' => $ajeno->id, 'quantity' => '60',
        ])
        ->assertNotFound();
});

it('explains itself instead of looping when a role opens no door at all', function (): void {
    // Un rol con permisos que no abren NINGUNA puerta: ni gestión del
    // negocio ni caja. Antes se le mandaba a /business, que le respondía
    // 403; y mandarlo al login habría sido un bucle, porque el login
    // redirige a HomeForUser.
    $plantilla = RoleTemplate::query()->create([
        'label' => 'Terminales',
        'description' => 'Solo administra dispositivos del POS.',
        'permissions' => ['pos_devices.manage'],
    ]);
    $plantilla->forceFill([
        'name' => 'terminales',
        'kind' => RoleKind::Account->value,
    ])->save();
    app(ApplyRoleTemplates::class)();

    $nadie = app(CreateTenantUser::class)(
        $this->negocio, 'Nadie', 'nadie@bar.test', 'Secreta-2026', 'terminales',
    );

    $this->actingAs($nadie)
        ->get('/entrar')
        ->assertOk()
        ->assertSee('no tiene ninguna pantalla asignada', false);

    $this->assertGuest();
});
