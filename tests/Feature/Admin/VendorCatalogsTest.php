<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Exceptions\CatalogInUseException;
use App\Domains\Platform\Models\FoodType;
use App\Domains\Platform\Models\VendorType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\Admin\Resources\FoodTypes\Pages\ListFoodTypes;
use App\Filament\Admin\Resources\VendorTypes\Pages\ListVendorTypes;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Los catálogos de clasificación de comercios: el superadmin los crea en
 * /admin y las cuentas los consumen. En uso, no se eliminan.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create());
    Filament::setCurrentPanel('admin');
});

afterEach(fn () => app(TenantContext::class)->clear());

it('creates catalog entries from the admin panel', function (): void {
    Livewire::test(ListVendorTypes::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Food Truck'])
        ->assertHasNoActionErrors();

    Livewire::test(ListFoodTypes::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Dominicana'])
        ->assertHasNoActionErrors();

    expect(VendorType::query()->where('name', 'Food Truck')->exists())->toBeTrue()
        ->and(FoodType::query()->where('name', 'Dominicana')->exists())->toBeTrue();
});

it('refuses to delete a type with vendors classified under it', function (): void {
    $tipo = VendorType::query()->create(['name' => 'Cervecería']);

    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    app(TenantContext::class)->runAs($organizer, function () use ($tipo): void {
        $vendor = app(CreateVendor::class)('La Cervecería');
        $vendor->update(['vendor_type_id' => $tipo->id]);
    });

    expect(fn () => $tipo->delete())->toThrow(CatalogInUseException::class);
});
