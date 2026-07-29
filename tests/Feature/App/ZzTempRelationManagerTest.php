<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Events\EventResource;
use App\Filament\App\Resources\Events\Pages\EditEvent;
use App\Filament\App\Resources\Events\RelationManagers\OutletsRelationManager;
use App\Filament\App\Resources\Events\RelationManagers\VendorsRelationManager;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $ctx = app(TenantContext::class);
    [$this->event, $this->vendor] = $ctx->runAs($this->organizer, fn (): array => [
        app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2)),
        app(CreateVendor::class)('Bar Manolo'),
    ]);
    $ctx->clear();

    foreach (Role::cases() as $role) {
        $this->{$role->value} = app(CreateTenantUser::class)(
            $this->organizer, ucfirst($role->value), $role->value.'@b.test', 'Secreta-2026', $role,
        );
    }
});

afterEach(fn () => app(TenantContext::class)->clear());

it('reports relation manager gate per role', function (): void {
    $lines = [];
    foreach (Role::cases() as $role) {
        $user = $this->{$role->value};
        signInTo($this, $user, $this->organizer);

        $pageOk = EventResource::canEdit($this->event) ? 'SI' : 'NO';
        $vendorsRm = VendorsRelationManager::canViewForRecord($this->event, EditEvent::class) ? 'SI' : 'NO';
        $outletsRm = OutletsRelationManager::canViewForRecord($this->event, EditEvent::class) ? 'SI' : 'NO';
        $perms = implode(',', array_filter([
            $user->can(Permission::EventsManage->value) ? 'events.manage' : null,
            $user->can(Permission::VendorsManage->value) ? 'vendors.manage' : null,
            $user->can(Permission::OperatingUnitsManage->value) ? 'op_units.manage' : null,
        ]));

        $lines[] = str_pad($role->value, 16)
            ."pagina_evento=$pageOk  RM_negocios=$vendorsRm  RM_puntos=$outletsRm   [$perms]";
    }
    fwrite(STDERR, "\n=== GATE DE LOS RELATION MANAGERS (mundo organizador) ===\n".implode("\n", $lines)."\n");

    expect(true)->toBeTrue();
});

it('checks whether a cashier can mount the vendors relation manager directly', function (): void {
    signInTo($this, $this->cashier, $this->organizer);

    $result = 'PERMITIDO';
    try {
        Livewire::test(VendorsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])->assertOk();
    } catch (Throwable $e) {
        $result = 'BLOQUEADO ('.class_basename($e).')';
    }
    fwrite(STDERR, "\ncashier monta VendorsRelationManager: $result\n");

    expect(true)->toBeTrue();
});

it('checks whether a cashier can actually attach a vendor through it', function (): void {
    signInTo($this, $this->cashier, $this->organizer);

    $before = app(TenantContext::class)->runAs($this->organizer, fn () => $this->event->vendors()->count());

    $result = 'OK';
    try {
        Livewire::test(VendorsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $this->vendor->getKey(),
            'commission_bps' => 10,
        ]);
    } catch (Throwable $e) {
        $result = 'BLOQUEADO ('.class_basename($e).')';
    }

    $after = app(TenantContext::class)->runAs($this->organizer, fn () => $this->event->vendors()->count());
    fwrite(STDERR, "\ncashier invita negocio al evento: $result | negocios antes=$before despues=$after\n");

    expect(true)->toBeTrue();
});

it('checks whether a cashier can create an event outlet through the relation manager', function (): void {
    // Primero dejamos el negocio invitado, para que el select tenga opciones.
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(\App\Domains\EventManagement\Actions\InviteVendorToEvent::class)($this->event, $this->vendor, 0);
    });

    signInTo($this, $this->cashier, $this->organizer);

    $before = EventOutlet::withoutGlobalScopes()->count();
    $result = 'OK';
    try {
        Livewire::test(OutletsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])->callAction(TestAction::make('create')->table(), data: [
            'vendor_id' => $this->vendor->getKey(),
            'name' => 'Barra pirata',
            'kind' => OperatingUnitKind::Bar->value,
            'status' => OperatingUnitStatus::Active->value,
        ]);
    } catch (Throwable $e) {
        $result = 'BLOQUEADO ('.class_basename($e).': '.substr($e->getMessage(), 0, 90).')';
    }
    $after = EventOutlet::withoutGlobalScopes()->count();

    fwrite(STDERR, "\ncashier crea punto de venta: $result | puntos antes=$before despues=$after\n");

    expect(true)->toBeTrue();
});
