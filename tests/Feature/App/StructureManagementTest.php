<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Branches\Pages\CreateBranch;
use App\Filament\App\Resources\Events\Pages\CreateEvent as CreateEventPage;
use App\Filament\App\Resources\Events\Pages\EditEvent;
use App\Filament\App\Resources\Events\RelationManagers\OutletsRelationManager;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->business = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);

    $this->businessOwner = app(CreateTenantUser::class)($this->business, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);
    $this->organizerOwner = app(CreateTenantUser::class)($this->organizer, 'Beto', 'beto@prod.test', 'Secreta-2026', Role::Owner);
    $this->cashier = app(CreateTenantUser::class)($this->business, 'Carla', 'carla@bar.test', 'Secreta-2026', Role::Cashier);

    $this->context = app(TenantContext::class);
});

afterEach(fn () => app(TenantContext::class)->clear());

describe('alta de sucursales', function (): void {
    it('creates a branch with everything the form asked for', function (): void {
        signInTo($this, $this->businessOwner, $this->business);

        Livewire::test(CreateBranch::class)
            ->fillForm([
                'name' => 'Sucursal Malecón',
                'kind' => OperatingUnitKind::Bar->value,
                'status' => OperatingUnitStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $branch = OperatingUnit::query()->where('name', 'Sucursal Malecón')->sole();

        expect($branch)
            ->tenant_id->toBe($this->business->id)
            ->type->toBe(OperatingUnitType::Branch)
            ->kind->toBe(OperatingUnitKind::Bar)
            ->status->toBe(OperatingUnitStatus::Active)
            ->event_id->toBeNull();
    });

    it('rejects a duplicate branch name with a form error, not a crash', function (): void {
        signInTo($this, $this->businessOwner, $this->business);
        app(App\Domains\Business\Actions\CreateBranch::class)('Sucursal Centro');

        Livewire::test(CreateBranch::class)
            ->fillForm([
                'name' => 'Sucursal Centro',
                'kind' => OperatingUnitKind::Mixed->value,
                'status' => OperatingUnitStatus::Active->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        expect(OperatingUnit::query()->where('name', 'Sucursal Centro')->count())->toBe(1);
    });

    it('keeps a cashier away from creating branches', function (): void {
        signInTo($this, $this->cashier, $this->business);

        Livewire::test(CreateBranch::class)->assertForbidden();
    });
});

describe('alta de eventos y sus puntos de venta', function (): void {
    it('creates an event with everything the form asked for', function (): void {
        signInTo($this, $this->organizerOwner, $this->organizer);

        Livewire::test(CreateEventPage::class)
            ->fillForm([
                'name' => 'Festival del Mar',
                'venue' => 'Malecón',
                'starts_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addWeeks(2)->format('Y-m-d H:i:s'),
                'status' => EventStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Event::query()->where('name', 'Festival del Mar')->sole())
            ->venue->toBe('Malecón')
            ->status->toBe(EventStatus::Draft)
            ->tenant_id->toBe($this->organizer->id);
    });

    it('refuses an event that ends before it starts', function (): void {
        signInTo($this, $this->organizerOwner, $this->organizer);

        Livewire::test(CreateEventPage::class)
            ->fillForm([
                'name' => 'Festival Imposible',
                'starts_at' => now()->addWeeks(2)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'status' => EventStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    });

    it('adds outlets to an event from its relation manager', function (): void {
        $event = $this->context->runAs($this->organizer, fn () => app(CreateEvent::class)(
            'Festival del Mar', now()->addWeek(), now()->addWeeks(2)
        ));

        signInTo($this, $this->organizerOwner, $this->organizer);

        Livewire::test(OutletsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callAction(TestAction::make('create')->table(), data: [
                'name' => 'Cocina central',
                'kind' => OperatingUnitKind::Kitchen->value,
                'status' => OperatingUnitStatus::Active->value,
            ])
            ->assertHasNoActionErrors();

        expect(OperatingUnit::query()->where('name', 'Cocina central')->sole())
            ->type->toBe(OperatingUnitType::EventOutlet)
            ->kind->toBe(OperatingUnitKind::Kitchen)
            ->status->toBe(OperatingUnitStatus::Active)
            ->event_id->toBe($event->id)
            ->tenant_id->toBe($this->organizer->id);
    });
});
