<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * Hito 2 del panel nuevo: Negocios (lista + alta + edición) y Eventos
 * (lista + alta + detalle). Mismas fronteras de siempre.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
    });

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('lists vendors and creates one landing on its profile', function (): void {
    $this->actingAs($this->owner)
        ->get('/panel/comercios')
        ->assertOk()
        ->assertSee('Tacos del Puerto');

    $this->actingAs($this->owner)
        ->post('/panel/comercios', [
            'name' => 'La Cervecería',
            'rnc' => '1-31-24680-9',
            'contact_name' => 'Pedro',
        ])
        ->assertRedirect();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        expect(Vendor::query()->where('name', 'La Cervecería')->sole())
            ->rnc->toBe('131246809');
    });
});

it('updates vendor data including suspension', function (): void {
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/datos", [
            'name' => 'Tacos del Puerto',
            'status' => 'suspended',
        ])
        ->assertRedirect();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        expect($this->vendor->fresh()->status)->toBe(VendorStatus::Suspended);
    });
});

it('lists events and creates one landing on its detail', function (): void {
    $this->actingAs($this->owner)
        ->get('/panel/eventos')
        ->assertOk()
        ->assertSee('Bocao 2026');

    $this->actingAs($this->owner)
        ->post('/panel/eventos', [
            'name' => 'Bocao 2027',
            'venue' => 'Malecón',
            'starts_at' => now()->addYear()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addYear()->addDays(2)->format('Y-m-d\TH:i'),
        ])
        ->assertRedirect();

    expect(Event::query()->withoutGlobalScopes()->where('name', 'Bocao 2027')->exists())->toBeTrue();
});

it('shows the event detail with participants and outlets', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);
        outletFor($this->event, 'Barra', OperatingUnitKind::Bar, $this->vendor);
    });

    $this->actingAs($this->owner)
        ->get("/panel/eventos/{$this->event->id}")
        ->assertOk()
        ->assertSee('Tacos del Puerto')
        ->assertSee('Barra')
        ->assertSee('10.00');
});

it('keeps vendor staff out of every hito 2 screen', function (): void {
    $staff = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    $this->actingAs($staff)->get('/panel/comercios')->assertForbidden();
});

it('never shows another tenants event', function (): void {
    $otro = app(CreateTenant::class)('Otra', null, TenantType::Organizer);
    $ajeno = app(TenantContext::class)->runAs($otro, fn () => app(CreateEvent::class)('Ajeno', now()->addWeek(), now()->addWeeks(2)));

    $this->actingAs($this->owner)->get("/panel/eventos/{$ajeno->id}")->assertNotFound();
});
