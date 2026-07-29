<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * El camino real de un organizador recién dado de alta, por HTTP y pasando
 * por el middleware — no por Livewire directo. Es el flujo que un super
 * admin ejecuta al crear una cuenta nueva y entregarla a su dueño.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)(
        'Bocao Food Fest', null, TenantType::Organizer, TenantStatus::Active
    );

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Juan', 'juan@bocao.test', 'Secreta-2026', Role::Owner
    );
});

afterEach(fn () => app(TenantContext::class)->clear());

it('lets a fresh organizer owner reach the event list', function (): void {
    $this->actingAs($this->owner)->get('/app/events')->assertOk();
});

it('lets a fresh organizer owner reach the create event page', function (): void {
    $this->actingAs($this->owner)->get('/app/events/create')->assertOk();
});

it('lets a fresh organizer owner reach the catalog', function (): void {
    $this->actingAs($this->owner)->get('/app/products')->assertOk();
});

it('keeps the branches screen out of an organizer account', function (): void {
    $this->actingAs($this->owner)->get('/app/branches')->assertForbidden();
});
