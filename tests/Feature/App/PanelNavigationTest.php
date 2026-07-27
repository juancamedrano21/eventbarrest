<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Branches\BranchResource;
use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Filament\App\Resources\Events\EventResource;
use App\Filament\App\Resources\Events\Pages\ListEvents;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->business = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);

    $this->businessOwner = app(CreateTenantUser::class)($this->business, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);
    $this->organizerOwner = app(CreateTenantUser::class)($this->organizer, 'Beto', 'beto@prod.test', 'Secreta-2026', Role::Owner);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Livewire::test monta el componente sin pasar por el middleware, así que el
 * contexto de tenant se fija a mano — igual que haría SetTenantContext en una
 * petición real. Sin él, el aislamiento falla cerrado y no se ve ningún dato.
 */
function signInTo(object $test, $user, $tenant): void
{
    $test->actingAs($user);
    app(TenantContext::class)->set($tenant);
    actAsTenantPermissions($tenant->id);
    Filament::setCurrentPanel('app');
}

it('shows branches only to business accounts', function (): void {
    signInTo($this, $this->businessOwner, $this->business);

    expect(BranchResource::shouldRegisterNavigation())->toBeTrue()
        ->and(EventResource::shouldRegisterNavigation())->toBeFalse();
});

it('shows events only to organizer accounts', function (): void {
    signInTo($this, $this->organizerOwner, $this->organizer);

    expect(EventResource::shouldRegisterNavigation())->toBeTrue()
        ->and(BranchResource::shouldRegisterNavigation())->toBeFalse();
});

it('forbids a business account from opening the events screen', function (): void {
    signInTo($this, $this->businessOwner, $this->business);

    Livewire::test(ListEvents::class)->assertForbidden();
});

it('forbids an organizer account from opening the branches screen', function (): void {
    signInTo($this, $this->organizerOwner, $this->organizer);

    Livewire::test(ListBranches::class)->assertForbidden();
});

it('lists only the branches of the signed-in business', function (): void {
    $context = app(TenantContext::class);
    $mine = $context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

    $otherBusiness = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $theirs = $context->runAs($otherBusiness, fn () => app(CreateBranch::class)('Sucursal Ajena'));

    signInTo($this, $this->businessOwner, $this->business);

    Livewire::test(ListBranches::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('never lists event outlets among the branches', function (): void {
    $context = app(TenantContext::class);
    $branch = $context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

    $outlet = $context->runAs($this->organizer, function () {
        $event = app(CreateEvent::class)('Festival', now()->addWeek(), now()->addWeeks(2));

        return app(CreateEventOutlet::class)($event, 'Barra principal', OperatingUnitKind::Bar);
    });

    signInTo($this, $this->businessOwner, $this->business);

    Livewire::test(ListBranches::class)
        ->assertCanSeeTableRecords([$branch])
        ->assertCanNotSeeTableRecords([$outlet]);
});

it('lists the events of the signed-in organizer', function (): void {
    $context = app(TenantContext::class);
    $mine = $context->runAs($this->organizer, fn () => app(CreateEvent::class)(
        'Festival del Mar', now()->addWeek(), now()->addWeeks(2)
    ));

    $otherOrganizer = app(CreateTenant::class)('Productora Ajena', null, TenantType::Organizer);
    $theirs = $context->runAs($otherOrganizer, fn () => app(CreateEvent::class)(
        'Festival Ajeno', now()->addWeek(), now()->addWeeks(2)
    ));

    signInTo($this, $this->organizerOwner, $this->organizer);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
