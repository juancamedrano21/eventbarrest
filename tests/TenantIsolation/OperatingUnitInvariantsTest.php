<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Actions\CreateBranch;
use App\Domains\Operations\Actions\CreateEventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Exceptions\TenantTypeIsImmutableException;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Los invariantes de los dos mundos, verificados contra los ataques reales
 * encontrados en auditoría. Viven en el modelo y en el query builder, no solo
 * en las acciones: cualquier seeder, importador o job futuro escribe por ahí.
 */
beforeEach(function (): void {
    $this->business = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);
    $this->rival = app(CreateTenant::class)('Productora Rival', null, TenantType::Organizer);
    $this->context = app(TenantContext::class);

    $this->event = $this->context->runAs($this->organizer, fn () => app(CreateEvent::class)(
        'Festival del Mar', now()->addWeek(), now()->addWeeks(2)
    ));
    $this->rivalEvent = $this->context->runAs($this->rival, fn () => app(CreateEvent::class)(
        'Festival Rival', now()->addWeek(), now()->addWeeks(2)
    ));
});

afterEach(fn () => app(TenantContext::class)->clear());

describe('el modelo defiende los mundos, no solo las acciones', function (): void {
    it('refuses an event created straight on a business account', function (): void {
        $this->context->runAs($this->business, fn () => Event::create([
            'name' => 'Festival Fantasma',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(2),
        ]));
    })->throws(InvalidOperatingUnitException::class);

    it('refuses a loose branch created straight on an organizer account', function (): void {
        $this->context->runAs($this->organizer, fn () => OperatingUnit::create([
            'name' => 'Sucursal Imposible',
            'kind' => OperatingUnitKind::Mixed,
        ]));
    })->throws(InvalidOperatingUnitException::class);

    it('refuses an outlet hanging from another accounts event', function (): void {
        $this->context->runAs($this->organizer, function (): void {
            $unit = new OperatingUnit(['name' => 'Barra intrusa', 'kind' => OperatingUnitKind::Bar]);
            $unit->event_id = $this->rivalEvent->id;
            $unit->save();
        });
    })->throws(InvalidOperatingUnitException::class);
});

describe('la unidad no cambia de evento', function (): void {
    it('refuses to move an outlet to another event with save', function (): void {
        $outlet = $this->context->runAs($this->organizer, fn () => app(CreateEventOutlet::class)(
            $this->event, 'Barra principal', OperatingUnitKind::Bar
        ));

        $other = $this->context->runAs($this->organizer, fn () => app(CreateEvent::class)(
            'Otro Festival', now()->addMonth(), now()->addMonth()->addDays(2)
        ));

        $this->context->set($this->organizer);
        $outlet->event_id = $other->id;
        $outlet->save();
    })->throws(InvalidOperatingUnitException::class);

    it('refuses to move an outlet with a mass update', function (): void {
        $this->context->runAs($this->organizer, fn () => app(CreateEventOutlet::class)(
            $this->event, 'Barra principal', OperatingUnitKind::Bar
        ));

        $this->context->set($this->organizer);
        OperatingUnit::query()->update(['event_id' => $this->rivalEvent->id]);
    })->throws(InvalidOperatingUnitException::class);

    it('refuses to turn a branch into an event outlet with a mass update', function (): void {
        $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

        $this->context->set($this->business);
        OperatingUnit::query()->update(['event_id' => $this->rivalEvent->id]);
    })->throws(InvalidOperatingUnitException::class);

    it('still allows ordinary edits', function (): void {
        $outlet = $this->context->runAs($this->organizer, fn () => app(CreateEventOutlet::class)(
            $this->event, 'Barra principal', OperatingUnitKind::Bar
        ));

        $this->context->set($this->organizer);
        $outlet->update(['name' => 'Barra VIP', 'kind' => OperatingUnitKind::Mixed]);

        expect($outlet->fresh())
            ->name->toBe('Barra VIP')
            ->kind->toBe(OperatingUnitKind::Mixed)
            ->event_id->toBe($this->event->id)
            ->type->toBe(OperatingUnitType::EventOutlet);
    });
});

describe('el tipo de cuenta es inmutable', function (): void {
    it('refuses to change the account type', function (): void {
        $this->business->type = TenantType::Organizer;
        $this->business->save();
    })->throws(TenantTypeIsImmutableException::class);

    it('ignores the type coming from mass assignment', function (): void {
        $this->business->update(['name' => 'Bar Renombrado', 'type' => TenantType::Organizer]);

        expect($this->business->fresh())
            ->name->toBe('Bar Renombrado')
            ->type->toBe(TenantType::Business);
    });
});

describe('la base de datos también protege', function (): void {
    it('refuses two branches with the same name in one account', function (): void {
        $this->context->set($this->business);
        app(CreateBranch::class)('Sucursal Centro');

        expect(fn () => app(CreateBranch::class)('Sucursal Centro'))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows the same branch name in different accounts', function (): void {
        $otherBusiness = app(CreateTenant::class)('Otro Bar', null, TenantType::Business);

        $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));
        $this->context->runAs($otherBusiness, fn () => app(CreateBranch::class)('Sucursal Centro'));

        expect(DB::table('operating_units')->where('name', 'Sucursal Centro')->count())->toBe(2);
    });

    it('refuses two outlets with the same name in one event', function (): void {
        $this->context->set($this->organizer);
        app(CreateEventOutlet::class)($this->event, 'Barra principal', OperatingUnitKind::Bar);

        expect(fn () => app(CreateEventOutlet::class)($this->event, 'Barra principal', OperatingUnitKind::Bar))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows the same outlet name in different events', function (): void {
        $this->context->set($this->organizer);
        $other = app(CreateEvent::class)('Otro Festival', now()->addMonth(), now()->addMonth()->addDays(2));

        app(CreateEventOutlet::class)($this->event, 'Barra principal', OperatingUnitKind::Bar);
        app(CreateEventOutlet::class)($other, 'Barra principal', OperatingUnitKind::Bar);

        expect(DB::table('operating_units')->where('name', 'Barra principal')->count())->toBe(2);
    });
});
