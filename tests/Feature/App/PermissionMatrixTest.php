<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Events\RelationManagers\OutletsRelationManager;
use App\Filament\App\Resources\Events\RelationManagers\VendorsRelationManager;

/**
 * La matriz observada, rol por rol y pantalla por pantalla. Es el contrato
 * de "quién puede qué" y la red que faltaba: hasta ahora cada pantalla se
 * probaba suelta y los huecos entre ellas no los veía nadie.
 */
function accessMatrix(object $test, $user, array $paths): array
{
    $observed = [];

    foreach ($paths as $path) {
        $observed[$path] = $test->actingAs($user)->get($path)->getStatusCode();
    }

    return $observed;
}

afterEach(fn () => app(TenantContext::class)->clear());

describe('cuenta de organizador', function (): void {
    beforeEach(function (): void {
        $this->tenant = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
        $this->paths = ['/app', '/app/events', '/app/vendors', '/app/products', '/app/users', '/app/stock-levels'];
    });

    it('gives the owner every screen', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        expect(accessMatrix($this, $owner, $this->paths))->each->toBe(200);
    });

    it('gives the event manager events but not businesses, catalog or team', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Eva', 'eva@x.test', 'Secreta-2026', Role::EventManager);

        $observed = accessMatrix($this, $user, $this->paths);

        expect($observed['/app'])->toBe(200)
            ->and($observed['/app/events'])->toBe(200)
            ->and($observed['/app/vendors'])->toBe(403)
            ->and($observed['/app/products'])->toBe(403)
            ->and($observed['/app/users'])->toBe(403);
    });

    it('gives the warehouse role inventory but nothing else', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Wally', 'wally@x.test', 'Secreta-2026', Role::Warehouse);

        $observed = accessMatrix($this, $user, $this->paths);

        expect($observed['/app/stock-levels'])->toBe(200)
            ->and($observed['/app/events'])->toBe(403)
            ->and($observed['/app/vendors'])->toBe(403)
            ->and($observed['/app/users'])->toBe(403);
    });

    it('keeps the cashier out of the management panel entirely', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Caro', 'caro@x.test', 'Secreta-2026', Role::Cashier);

        // Su trabajo ocurre en el POS: aquí solo vería un menú vacío.
        expect($this->actingAs($user)->get('/app')->getStatusCode())->toBe(403);
    });
});

describe('los relation managers del evento respetan permisos', function (): void {
    beforeEach(function (): void {
        $this->tenant = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
        $this->event = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2))
        );
    });

    it('lets the owner manage participating businesses and outlets', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
        signInTo($this, $owner, $this->tenant);

        expect(VendorsRelationManager::canViewForRecord($this->event, 'x'))->toBeTrue()
            ->and(OutletsRelationManager::canViewForRecord($this->event, 'x'))->toBeTrue();
    });

    it('keeps a warehouse user out of both', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Wally', 'wally@x.test', 'Secreta-2026', Role::Warehouse);
        signInTo($this, $user, $this->tenant);

        expect(VendorsRelationManager::canViewForRecord($this->event, 'x'))->toBeFalse()
            ->and(OutletsRelationManager::canViewForRecord($this->event, 'x'))->toBeFalse();
    });
});

describe('cuenta de negocio', function (): void {
    beforeEach(function (): void {
        $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    });

    it('gives the owner branches and catalog, never events or businesses', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        $observed = accessMatrix($this, $owner, ['/app/branches', '/app/products', '/app/events', '/app/vendors']);

        expect($observed['/app/branches'])->toBe(200)
            ->and($observed['/app/products'])->toBe(200)
            ->and($observed['/app/events'])->toBe(403)
            ->and($observed['/app/vendors'])->toBe(403);
    });

    it('keeps the event manager away from branches', function (): void {
        // El permiso de sucursales es de administración de cuenta, no de
        // gestión de eventos: un gerente de eventos no administra sucursales.
        $user = app(CreateTenantUser::class)($this->tenant, 'Eva', 'eva@x.test', 'Secreta-2026', Role::EventManager);

        expect($this->actingAs($user)->get('/app/branches')->getStatusCode())->toBe(403);
    });
});
