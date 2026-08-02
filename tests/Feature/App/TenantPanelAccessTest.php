<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Actions\SuspendTenant;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto');
    $this->owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);
});

it('lets a tenant user into the business panel', function (): void {
    $this->actingAs($this->owner)->get('/app')->assertOk();
});

it('keeps tenant users out of the platform panel', function (): void {
    $this->actingAs($this->owner)->get('/saas-admin')->assertForbidden();
});

it('keeps platform staff out of the business panel', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create())
        ->get('/app')
        ->assertForbidden();
});

it('locks out the whole team when the tenant is suspended', function (): void {
    app(SuspendTenant::class)($this->tenant);

    $this->actingAs($this->owner->fresh())->get('/app')->assertForbidden();
});

it('sets the tenant context from the authenticated user', function (): void {
    expect(app(TenantContext::class)->check())->toBeFalse();

    $this->actingAs($this->owner)->get('/app')->assertOk();

    expect(app(TenantContext::class)->id())->toBe($this->tenant->id);
});

it('leaves the context unset for a suspended tenant', function (): void {
    app(SuspendTenant::class)($this->tenant);

    $this->actingAs($this->owner->fresh())->get('/app');

    expect(app(TenantContext::class)->check())->toBeFalse();
});
