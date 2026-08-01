<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Vendors\Pages\ViewVendor;
use App\Filament\App\Resources\Vendors\RelationManagers\OutletsRelationManager;
use App\Filament\App\Resources\Vendors\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\Vendors\RelationManagers\UsersRelationManager;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * El perfil del comercio: el dueño del evento entra a él y desde ahí vive
 * todo lo suyo — equipo, eventos, puestos y catálogo (este en solo lectura:
 * el organizador mira, el comercio opera).
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            Product::create(['category_id' => $cat->id, 'name' => 'Taco al pastor', 'type' => ProductType::Simple, 'price_cents' => 25000]);
        });
    });

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('opens the vendor profile for the organizer', function (): void {
    expect($this->actingAs($this->owner)->get("/app/vendors/{$this->vendor->id}")->getStatusCode())->toBe(200);
});

it('keeps vendor staff out of the profile: it is the organizers view', function (): void {
    $staff = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    expect($this->actingAs($staff)->get("/app/vendors/{$this->vendor->id}")->getStatusCode())->toBe(403);
});

it('creates vendor staff from the profile, already attached to the vendor', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->vendor,
        'pageClass' => ViewVendor::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'name' => 'María',
            'username' => 'Maria',
            'email' => 'maria@tacos.test',
            'password' => 'Secreta-2026',
            'role' => 'vendor_manager',
        ])
        ->assertHasNoActionErrors();

    $maria = User::query()->where('email', 'maria@tacos.test')->sole();
    expect($maria->vendor_id)->toBe($this->vendor->id)
        ->and($maria->username)->toBe('maria');
});

it('creates an outlet from the profile choosing among its events', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    Livewire::test(OutletsRelationManager::class, [
        'ownerRecord' => $this->vendor,
        'pageClass' => ViewVendor::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'event_id' => $this->event->id,
            'name' => 'Puesto principal',
            'kind' => 'kitchen',
        ])
        ->assertHasNoActionErrors();

    $outlet = EventOutlet::query()->where('name', 'Puesto principal')->sole();
    expect($outlet->vendor_id)->toBe($this->vendor->id)
        ->and($outlet->event_id)->toBe($this->event->id);
});

it('shows the vendor catalog read only in the profile', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    Livewire::test(ProductsRelationManager::class, [
        'ownerRecord' => $this->vendor,
        'pageClass' => ViewVendor::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords(Product::query()->withoutGlobalScopes()->where('name', 'Taco al pastor')->get());
});
