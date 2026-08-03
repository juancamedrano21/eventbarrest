<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El freno del login del POS. La llave se compone con el usuario, no con la
 * IP a secas: en un festival TODAS las cajas salen por el mismo router, y un
 * limitador que solo mira la IP castiga a la caja de al lado por los errores
 * de otro — o directamente por sus aciertos, porque el middleware de Laravel
 * cuenta también los intentos que salen bien.
 */
beforeEach(function (): void {
    RateLimiter::clear('pos-login');

    $this->negocio = app(CreateTenant::class)('Bar Demo', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        app(CreateTenantUser::class)(
            $this->negocio, 'Caro', 'caro@bar.test', 'Secreta-2026', Role::Cashier, username: 'caro',
        );
        app(CreateTenantUser::class)(
            $this->negocio, 'Luis', 'luis@bar.test', 'Secreta-2026', Role::Cashier, username: 'luis',
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

it('never locks one cashier out because of another one at the same venue', function (): void {
    // Cinco intentos fallidos de Caro agotan SU cupo.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/pos/login', [
            'username' => 'caro', 'password' => 'mala', 'device_name' => 'Tablet 1',
        ])->assertStatus(422);
    }

    $this->postJson('/api/pos/login', [
        'username' => 'caro', 'password' => 'mala', 'device_name' => 'Tablet 1',
    ])->assertStatus(429);

    // Y Luis, en la misma IP, entra sin enterarse. Antes de arreglar la
    // llave esto era un 429: la sexta tablet del evento no podía abrir.
    $this->postJson('/api/pos/login', [
        'username' => 'luis', 'password' => 'Secreta-2026', 'device_name' => 'Tablet 2',
    ])->assertCreated();
});
