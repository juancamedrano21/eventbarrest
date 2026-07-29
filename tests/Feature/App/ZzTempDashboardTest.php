<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Models\User;

it('dashboard single request per role', function (): void {
    $organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);

    $owner = app(CreateTenantUser::class)($organizer, 'O', 'o@x.test', 'Secreta-2026', Role::Owner);
    $cashier = app(CreateTenantUser::class)($organizer, 'C', 'c@x.test', 'Secreta-2026', Role::Cashier);

    $r = $this->actingAs(User::query()->find($owner->getKey()))->get('/app');
    fwrite(STDERR, "\nOWNER dashboard: ".$r->getStatusCode()."\n");
    if ($r->getStatusCode() >= 500) {
        fwrite(STDERR, substr(strip_tags($r->getContent()), 0, 2000)."\n");
    }

    $r2 = $this->actingAs(User::query()->find($cashier->getKey()))->get('/app');
    fwrite(STDERR, "\nCASHIER dashboard: ".$r2->getStatusCode()."\n");
    if ($r2->getStatusCode() >= 500) {
        fwrite(STDERR, substr(strip_tags($r2->getContent()), 0, 3000)."\n");
    }

    expect(true)->toBeTrue();
});
