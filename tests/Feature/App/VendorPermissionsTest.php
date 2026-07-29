<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Vendors\Pages\ListVendors;
use App\Filament\App\Resources\Vendors\VendorResource;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Quién puede dar de alta negocios. Administrar la cuenta (decidir qué
 * negocios existen) no es lo mismo que gestionar un evento (decidir cuáles
 * participan), así que son permisos distintos.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Dueño', 'dueno@bocao.test', 'Secreta-2026', Role::Owner);
    $this->admin = app(CreateTenantUser::class)($this->organizer, 'Admin', 'admin@bocao.test', 'Secreta-2026', Role::Admin);
    $this->eventManager = app(CreateTenantUser::class)($this->organizer, 'Gerente', 'gerente@bocao.test', 'Secreta-2026', Role::EventManager);
    $this->cashier = app(CreateTenantUser::class)($this->organizer, 'Cajero', 'cajero@bocao.test', 'Secreta-2026', Role::Cashier);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('lets the owner create businesses', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    expect(VendorResource::canCreate())->toBeTrue();

    Livewire::test(ListVendors::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Bar Manolo',
            'status' => VendorStatus::Active->value,
        ])
        ->assertHasNoActionErrors();

    expect(Vendor::query()->where('name', 'Bar Manolo')->exists())->toBeTrue();
});

it('lets the admin create businesses too', function (): void {
    signInTo($this, $this->admin, $this->organizer);

    expect(VendorResource::canCreate())->toBeTrue();
    Livewire::test(ListVendors::class)->assertOk();
});

it('lets the event manager use events but not create businesses', function (): void {
    signInTo($this, $this->eventManager, $this->organizer);

    expect($this->eventManager->can(Permission::EventsManage->value))->toBeTrue()
        ->and($this->eventManager->can(Permission::VendorsManage->value))->toBeFalse()
        ->and(VendorResource::canViewAny())->toBeFalse();

    Livewire::test(ListVendors::class)->assertForbidden();
});

it('keeps a cashier away from businesses', function (): void {
    signInTo($this, $this->cashier, $this->organizer);

    Livewire::test(ListVendors::class)->assertForbidden();
});

it('reaches the business screen over HTTP as owner', function (): void {
    $this->actingAs($this->owner)->get('/app/vendors')->assertOk();
});

it('reaches the business screen over HTTP as admin', function (): void {
    $this->actingAs($this->admin)->get('/app/vendors')->assertOk();
});

it('says which permission is missing instead of a bare 403', function (): void {
    $this->actingAs($this->eventManager)->get('/app/vendors')->assertForbidden();
});

it('hides businesses from a business account entirely', function (): void {
    $bar = app(CreateTenant::class)('Bar Independiente', null, TenantType::Business);
    $barOwner = app(CreateTenantUser::class)($bar, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);

    signInTo($this, $barOwner, $bar);

    expect(VendorResource::shouldRegisterNavigation())->toBeFalse();
});

it('lets the event manager still invite businesses to an event', function (): void {
    // El conteo va dentro del contexto: fuera de él, el aislamiento por
    // cuenta falla cerrado y no vería nada — que es lo correcto.
    $participations = app(TenantContext::class)->runAs($this->organizer, function () {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $vendor = Vendor::factory()->create(['name' => 'Bar Manolo']);
        app(InviteVendorToEvent::class)($event, $vendor, 1000);

        return $vendor->events()->count();
    });

    expect($participations)->toBe(1);
});
