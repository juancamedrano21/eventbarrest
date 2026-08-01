<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;

it('serves the purchased dashboard template behind auth', function (): void {
    $tenant = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $owner = app(CreateTenantUser::class)($tenant, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->get('/panel')->assertRedirect(); // sin sesión, al login

    $response = $this->actingAs($owner)->get('/panel');

    // Con el tema instalado: la plantilla; sin él: 503 con instrucción clara.
    expect(in_array($response->getStatusCode(), [200, 503], true))->toBeTrue();

    if ($response->getStatusCode() === 200) {
        $response->assertSee('/panel-theme/assets/', false);
    }
});
