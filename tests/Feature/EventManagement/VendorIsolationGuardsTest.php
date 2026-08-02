<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Exceptions\CatalogException;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Actions\TransferStock;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * El aislamiento entre comercios vive en el DOMINIO, no solo en el panel:
 * estos guards protegen también a seeders, comandos, jobs y al POS que
 * vendrá. Cada comercio con su stock, su catálogo y sus recetas.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));

        $this->cerveceria = app(CreateVendor::class)('La Cervecería');
        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');

        app(InviteVendorToEvent::class)($event, $this->cerveceria);
        app(InviteVendorToEvent::class)($event, $this->tacos);

        $this->barra = outletFor($event, 'Barra', OperatingUnitKind::Bar, $this->cerveceria);
        $this->puesto = outletFor($event, 'Puesto', OperatingUnitKind::Kitchen, $this->tacos);

        $vendors = app(VendorContext::class);

        $this->ron = $vendors->runAs($this->cerveceria, fn () => InventoryItem::create([
            'name' => 'Ron añejo', 'base_unit' => MeasurementUnit::Milliliter, 'cost_cents' => 0,
        ]));
        $this->carne = $vendors->runAs($this->tacos, fn () => InventoryItem::create([
            'name' => 'Carne al pastor', 'base_unit' => MeasurementUnit::Gram, 'cost_cents' => 0,
        ]));
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('refuses stock of one vendor entering a unit of another', function (): void {
    // Compra del insumo de La Cervecería en el puesto de Tacos: ni el
    // organizador sin comercio activo puede cruzarlos.
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(RegisterPurchase::class)($this->puesto, $this->ron, 10, 100, 'Cruzada'),
    );
})->throws(InventoryException::class);

it('refuses transfers between units of different vendors', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(VendorContext::class)->runAs(
            $this->cerveceria,
            fn () => app(RegisterPurchase::class)($this->barra, $this->ron, 100, 95, 'Inicial'),
        );

        app(TransferStock::class)($this->barra, $this->puesto, $this->ron, 10);
    });
})->throws(InventoryException::class);

it('refuses a recipe that consumes another vendors ingredient', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(VendorContext::class)->runAs($this->cerveceria, function (): void {
            $categoria = Category::create(['name' => 'Tragos', 'dispatch' => DispatchArea::Bar]);
            $producto = Product::create([
                'category_id' => $categoria->id,
                'name' => 'Cuba Libre',
                'type' => ProductType::Recipe,
                'price_cents' => 40000,
            ]);

            // Insumo del OTRO comercio en la receta.
            $producto->recipeItems()->create([
                'inventory_item_id' => $this->carne->id,
                'quantity' => 60,
            ]);
        });
    });
})->throws(CatalogException::class);

it('refuses a product filed under another vendors category', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $categoriaTacos = app(VendorContext::class)->runAs(
            $this->tacos,
            fn () => Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]),
        );

        app(VendorContext::class)->runAs($this->cerveceria, fn () => Product::create([
            'category_id' => $categoriaTacos->id,
            'name' => 'Intruso',
            'type' => ProductType::Simple,
            'price_cents' => 10000,
        ]));
    });
})->throws(CatalogException::class);

it('refuses writing for another vendor with a vendor active', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(VendorContext::class)->runAs($this->cerveceria, function (): void {
            (new Category)->forceFill([
                'name' => 'Colada ajena',
                'dispatch' => DispatchArea::Bar,
                'vendor_id' => $this->tacos->id,
            ])->save();
        });
    });
})->throws(VendorException::class);

it('never lets a row change vendors', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->ron->vendor_id = $this->tacos->id;
        $this->ron->save();
    });
})->throws(VendorException::class);

it('fails closed when the vendor of a signed-in user cannot be resolved', function (): void {
    $user = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->cerveceria,
    );

    // Estado corrupto que el guard de Eloquent no ve (SQL directo): el
    // comercio apunta a otra cuenta. La petición debe negarse, no degradar
    // al usuario a la vista consolidada.
    $otra = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);
    $ajeno = app(TenantContext::class)->runAs($otra, fn () => app(CreateVendor::class)('Ajeno'));
    DB::table('users')->where('id', $user->id)->update(['vendor_id' => $ajeno->id]);

    expect($this->actingAs($user->fresh())->get('/event-vendor')->getStatusCode())->toBe(403);
});
