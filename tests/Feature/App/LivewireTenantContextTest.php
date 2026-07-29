<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\Middleware\SetTenantContext;
use App\Domains\Tenancy\TenantContext;

/**
 * Filament navega por SPA: al pulsar una entrada del menú, el contenido lo
 * pide a la ruta de Livewire, que es GLOBAL y no hereda los middleware del
 * panel. Cuando el contexto vivía solo en el panel, esa petición llegaba sin
 * equipo de permisos y respondía 403 mientras el menú —pintado en la carga
 * inicial— se veía perfecto. Por eso el contexto vive en el grupo web.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Juan', 'juan@bocao.test', 'Secreta-2026', Role::Owner);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('applies the tenant context on the global livewire route', function (): void {
    $middleware = collect(app('router')->getMiddlewareGroups()['web'] ?? []);

    expect($middleware)->toContain(SetTenantContext::class);
});

it('serves a resource page over HTTP without setting the context by hand', function (): void {
    // Por HTTP a propósito: Livewire::test monta el componente sin pasar por
    // middleware, así que no probaría lo que aquí importa.
    $this->actingAs($this->owner)->get('/app/vendors')->assertOk();
});

it('keeps working after navigating between screens', function (): void {
    $this->actingAs($this->owner);

    $this->get('/app/vendors')->assertOk();
    $this->get('/app/events')->assertOk();
    $this->get('/app/vendors')->assertOk();
});

it('lets the owner create a business end to end', function (): void {
    $vendor = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(CreateVendor::class)('Bar Manolo')
    );

    $this->actingAs($this->owner)->get('/app/vendors')->assertOk()->assertSee('Bar Manolo');

    expect($vendor->tenant_id)->toBe($this->organizer->id);
});
