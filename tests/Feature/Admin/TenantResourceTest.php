<?php

declare(strict_types=1);

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create());
    Filament::setCurrentPanel('admin');
});

it('lists tenants', function (): void {
    $tenants = Tenant::factory()->count(3)->create();

    Livewire::test(ListTenants::class)
        ->assertOk()
        ->assertCanSeeTableRecords($tenants);
});

it('creates a tenant normalizing the rnc', function (): void {
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Neón Club',
            'rnc' => '1-31-24680-9',
            'status' => TenantStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('name', 'Neón Club')->sole())
        ->rnc->toBe('131246809')
        ->status->toBe(TenantStatus::Active);
});

it('rejects an invalid rnc', function (): void {
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Bar Inválido',
            'rnc' => '12345',
            'status' => TenantStatus::Trial->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['rnc']);

    expect(Tenant::count())->toBe(0);
});

it('rejects a duplicate rnc', function (string $duplicate): void {
    Tenant::factory()->create(['rnc' => '131246809']);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Clon SRL',
            'rnc' => $duplicate,
            'status' => TenantStatus::Trial->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['rnc']);

    expect(Tenant::count())->toBe(1);
})->with([
    'idéntico' => '131246809',
    'con guiones' => '1-31-24680-9',
    'con espacios' => '131 246 809',
]);

it('rejects editing a tenant into another tenants rnc', function (string $duplicate): void {
    Tenant::factory()->create(['rnc' => '131246809']);
    $tenant = Tenant::factory()->create(['rnc' => '401234567']);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['rnc' => $duplicate])
        ->call('save')
        ->assertHasFormErrors(['rnc']);

    expect($tenant->fresh()->rnc)->toBe('401234567');
})->with(['131246809', '1-31-24680-9']);

it('lets a tenant keep its own rnc when editing', function (): void {
    $tenant = Tenant::factory()->create(['rnc' => '131246809']);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['name' => 'Mismo RNC', 'rnc' => '1-31-24680-9'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh())->name->toBe('Mismo RNC')->rnc->toBe('131246809');
});

it('allows creating without rnc', function (): void {
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Bar Informal',
            'rnc' => null,
            'status' => TenantStatus::Trial->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('name', 'Bar Informal')->sole()->rnc)->toBeNull();
});

it('edits a tenant', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Viejo Nombre']);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['name' => 'Nuevo Nombre'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->name)->toBe('Nuevo Nombre');
});

it('suspends a tenant from the table action and logs the activity', function (): void {
    $tenant = Tenant::factory()->create();

    Livewire::test(ListTenants::class)
        ->callAction(TestAction::make('suspender')->table($tenant));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and(
            Activity::query()
                ->where('log_name', 'platform')
                ->where('subject_type', Tenant::class)
                ->where('subject_id', $tenant->id)
                ->exists()
        )->toBeTrue();
});

it('activates a suspended tenant from the table action', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    Livewire::test(ListTenants::class)
        ->callAction(TestAction::make('activar')->table($tenant));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('shows only the action that applies to each status', function (): void {
    $active = Tenant::factory()->create();
    $suspended = Tenant::factory()->suspended()->create();

    Livewire::test(ListTenants::class)
        ->assertActionVisible(TestAction::make('suspender')->table($active))
        ->assertActionHidden(TestAction::make('activar')->table($active))
        ->assertActionVisible(TestAction::make('activar')->table($suspended))
        ->assertActionHidden(TestAction::make('suspender')->table($suspended));
});

it('offers no delete action: businesses are suspended, never erased', function (): void {
    $tenant = Tenant::factory()->create();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertActionDoesNotExist('delete');

    expect(Tenant::whereKey($tenant->id)->exists())->toBeTrue();
});

it('searches tenants by name and rnc', function (): void {
    $neon = Tenant::factory()->create(['name' => 'Neón Club', 'rnc' => '131246809']);
    $otro = Tenant::factory()->create(['name' => 'Terraza Sur', 'rnc' => '401234567']);

    Livewire::test(ListTenants::class)
        ->searchTable('Neón')
        ->assertCanSeeTableRecords([$neon])
        ->assertCanNotSeeTableRecords([$otro])
        ->searchTable('401234567')
        ->assertCanSeeTableRecords([$otro])
        ->assertCanNotSeeTableRecords([$neon]);
});

it('filters tenants by status', function (): void {
    $active = Tenant::factory()->create();
    $suspended = Tenant::factory()->suspended()->create();

    Livewire::test(ListTenants::class)
        ->filterTable('status', TenantStatus::Suspended->value)
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$active]);
});
