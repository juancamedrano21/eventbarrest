<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * La matriz OBSERVADA de rol × pantalla: no lo que el catálogo de permisos
 * promete, sino lo que cada rol recibe de verdad al pedir cada URL.
 *
 * Rescatada del panel Filament que se retiró, y reescrita contra las puertas
 * que la sustituyen. Es la red que impide que un permiso se conceda de más
 * sin que nadie se entere.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Qué código devuelve cada URL para este usuario.
 *
 * @param  array<int, string>  $paths
 * @return array<string, int>
 */
function matrizDe(object $test, User $user, array $paths): array
{
    $observado = [];

    foreach ($paths as $path) {
        $observado[$path] = $test->actingAs($user)->get($path)->getStatusCode();
    }

    return $observado;
}

describe('cuenta de negocio', function (): void {
    beforeEach(function (): void {
        $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

        app(TenantContext::class)->runAs($this->tenant, fn () => app(CreateBranch::class)('Sucursal Centro'));

        $this->paths = [
            '/business',
            '/business/menu',
            '/business/inventario',
            '/business/ventas',
            '/business/caja',
            '/business/sucursales',
            '/business/equipo',
            '/business/ajustes',
        ];
    });

    it('gives the owner every screen of the business', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        expect(matrizDe($this, $owner, $this->paths))->each->toBe(200);
    });

    it('gives the warehouse role inventory and nothing else', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Wally', 'wally@x.test', 'Secreta-2026', Role::Warehouse);

        $observado = matrizDe($this, $user, $this->paths);

        expect($observado['/business/inventario'])->toBe(200)
            ->and($observado['/business/menu'])->toBe(403)
            ->and($observado['/business/sucursales'])->toBe(403)
            ->and($observado['/business/equipo'])->toBe(403)
            ->and($observado['/business/ajustes'])->toBe(403)
            ->and($observado['/business/ventas'])->toBe(403);
    });

    it('gives the unit manager the money and the stock, never the team or the tax rule', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Gerardo', 'gera@x.test', 'Secreta-2026', Role::UnitManager);

        $observado = matrizDe($this, $user, $this->paths);

        expect($observado['/business/ventas'])->toBe(200)
            ->and($observado['/business/caja'])->toBe(200)
            ->and($observado['/business/inventario'])->toBe(200)
            ->and($observado['/business/equipo'])->toBe(403)
            ->and($observado['/business/ajustes'])->toBe(403)
            // branches.manage es accountOnly y ni el gerente lo tiene.
            ->and($observado['/business/sucursales'])->toBe(403);
    });

    it('sends the cashier to the pos instead of showing an empty panel', function (): void {
        $user = app(CreateTenantUser::class)(
            $this->tenant, 'Caro', 'caro@x.test', 'Secreta-2026', Role::Cashier, null, null, 'caro',
        );

        // Su trabajo entero ocurre en la caja: no se le da un 403 seco, se
        // le manda a donde sí tiene algo que hacer.
        expect(matrizDe($this, $user, $this->paths))->each->toBe(302);
        $this->actingAs($user)->get('/business')->assertRedirect('/pos');
    });
});

describe('cuenta de organizador', function (): void {
    beforeEach(function (): void {
        $this->tenant = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

        app(TenantContext::class)->runAs($this->tenant, fn () => app(CreateVendor::class)('Tacos del Puerto'));

        $this->paths = ['/event-panel', '/event-panel/eventos', '/event-panel/comercios'];
    });

    it('gives the owner every screen of the organizer', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        expect(matrizDe($this, $owner, $this->paths))->each->toBe(200);
    });

    it('gives the event manager events but not the businesses', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Eva', 'eva@x.test', 'Secreta-2026', Role::EventManager);

        $observado = matrizDe($this, $user, $this->paths);

        expect($observado['/event-panel/eventos'])->toBe(200)
            ->and($observado['/event-panel/comercios'])->toBe(403);
    });

    it('keeps the warehouse role out of events and businesses', function (): void {
        $user = app(CreateTenantUser::class)($this->tenant, 'Wally', 'wally@x.test', 'Secreta-2026', Role::Warehouse);

        $observado = matrizDe($this, $user, $this->paths);

        expect($observado['/event-panel/eventos'])->toBe(403)
            ->and($observado['/event-panel/comercios'])->toBe(403);
    });

    it('never lets an organizer account reach the business door', function (): void {
        $owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        // Sucursales no existen en este mundo: el rebote va a SU puerta, no
        // a un 403 sin salida.
        $this->actingAs($owner)->get('/business')->assertRedirect('/event-panel');
        $this->actingAs($owner)->get('/business/sucursales')->assertRedirect('/event-panel');
    });
});

describe('las fronteras entre mundos', function (): void {
    it('keeps vendor staff out of the account team, even by url', function (): void {
        $tenant = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
        $vendor = app(TenantContext::class)->runAs($tenant, fn () => app(CreateVendor::class)('Tacos'));

        $encargada = app(CreateTenantUser::class)(
            $tenant, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $vendor,
        );

        // Su puerta es /event-vendor: el panel de la cuenta la rebota.
        $this->actingAs($encargada)->get('/event-panel')->assertRedirect('/event-vendor');
        $this->actingAs($encargada)->get('/business')->assertRedirect('/event-vendor');
    });

    it('locks out the whole team when the account is suspended', function (): void {
        $tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
        $owner = app(CreateTenantUser::class)($tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($owner)->get('/business')->assertForbidden();
    });

    it('keeps platform staff out of the client panels', function (): void {
        $superadmin = User::factory()->platformAdmin()->create();

        $this->actingAs($superadmin)->get('/business')->assertRedirect('/saas-admin');
    });
});
