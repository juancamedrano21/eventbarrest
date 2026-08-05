<?php

declare(strict_types=1);

use App\Domains\EventApp\Actions\IssueEventPublicCode;
use App\Domains\EventApp\Exceptions\EventAppException;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * El código público del evento: lo único que la app lleva compilado dentro
 * para saber a qué festival pertenece.
 *
 * Cambiarlo deja sin servidor a todas las apps ya instaladas, que llevan el
 * viejo y no pueden cambiarlo sin pasar por tienda. De ahí lo que se prueba:
 * que emitirlo es idempotente, que reemplazarlo exige pedirlo, y que dos
 * cuentas no pueden repartir el mismo.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

it('keeps the code it already handed out', function (): void {
    $original = (string) $this->evento->public_code;

    expect(app(IssueEventPublicCode::class)($this->evento))->toBe($original);
});

it('takes a vanity code the generator could never produce', function (): void {
    // La O de BOCAO no está en el alfabeto dictable, a propósito: un código
    // de vanidad es una decisión de marketing que alguien toma a mano.
    $codigo = app(IssueEventPublicCode::class)($this->evento, 'bocao-26');

    expect($codigo)->toBe('BOCAO26');

    $this->getJson('/api/event-app/eventos/BOCAO26/manifiesto')->assertOk()
        ->assertJsonPath('evento.codigo', 'BOCAO26');
});

it('refuses a vanity code another festival already uses', function (): void {
    app(IssueEventPublicCode::class)($this->evento, 'BOCAO26');

    $otro = app(CreateTenant::class)('Otro Organizador', null, TenantType::Organizer);

    $ajeno = app(TenantContext::class)->runAs($otro, fn (): Event => app(CreateEvent::class)(
        'Otro Festival', now(), now()->addDay(), null, EventStatus::Active,
    ));

    // Y falla aunque sea de OTRA cuenta: el índice es global porque el
    // teléfono resuelve el código sin saber de quién es el festival.
    expect(fn () => app(IssueEventPublicCode::class)($ajeno, 'bocao 26'))
        ->toThrow(EventAppException::class);
});

it('refuses a code too short to be anyone', function (): void {
    expect(fn () => app(IssueEventPublicCode::class)($this->evento, 'ab'))
        ->toThrow(EventAppException::class);
});

it('hands a code to an event that somehow got created without one', function (): void {
    // Un seeder o un import escribiendo por el modelo se salta CreateEvent.
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento->setAttribute('public_code', null);
        $this->evento->save();
    });

    $this->artisan('event-app:codigos')->assertSuccessful();

    expect(Event::query()->withoutTenancy()->find($this->evento->id)?->public_code)
        ->toBeString()
        ->toHaveLength(8);
});

it('refuses to set a code by hand without saying which event', function (): void {
    $this->artisan('event-app:codigos', ['--codigo' => 'BOCAO26'])->assertFailed();
});
