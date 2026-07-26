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
use App\Domains\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->business = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);
    $this->context = app(TenantContext::class);
});

afterEach(fn () => app(TenantContext::class)->clear());

describe('cuenta de negocio', function (): void {
    it('creates branches', function (): void {
        $branch = $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

        expect($branch->type)->toBe(OperatingUnitType::Branch)
            ->and($branch->event_id)->toBeNull()
            ->and($branch->kind)->toBe(OperatingUnitKind::Mixed)
            ->and($branch->tenant_id)->toBe($this->business->id);
    });

    it('cannot create events', function (): void {
        $this->context->runAs($this->business, fn () => app(CreateEvent::class)(
            'Festival Imposible', now()->addWeek(), now()->addWeeks(2)
        ));
    })->throws(InvalidOperatingUnitException::class);
});

describe('cuenta de organizador', function (): void {
    it('creates events with their outlets', function (): void {
        [$event, $bar, $kitchen, $outletCount] = $this->context->runAs($this->organizer, function () {
            $event = app(CreateEvent::class)('Festival del Mar', now()->addWeek(), now()->addWeeks(2), 'Malecón');
            $bar = app(CreateEventOutlet::class)($event, 'Barra principal', OperatingUnitKind::Bar);
            $kitchen = app(CreateEventOutlet::class)($event, 'Cocina', OperatingUnitKind::Kitchen);

            return [$event, $bar, $kitchen, $event->operatingUnits()->count()];
        });

        expect($event->venue)->toBe('Malecón')
            ->and($bar->type)->toBe(OperatingUnitType::EventOutlet)
            ->and($bar->kind)->toBe(OperatingUnitKind::Bar)
            ->and($bar->event_id)->toBe($event->id)
            ->and($kitchen->kind)->toBe(OperatingUnitKind::Kitchen)
            ->and($outletCount)->toBe(2);
    });

    it('cannot create loose branches', function (): void {
        $this->context->runAs($this->organizer, fn () => app(CreateBranch::class)('Sucursal Imposible'));
    })->throws(InvalidOperatingUnitException::class);
});

describe('los dos mundos no se tocan', function (): void {
    it('refuses to attach an outlet to another accounts event', function (): void {
        $event = $this->context->runAs($this->organizer, fn () => app(CreateEvent::class)(
            'Festival Ajeno', now()->addWeek(), now()->addWeeks(2)
        ));

        $other = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);

        $this->context->runAs($other, fn () => app(CreateEventOutlet::class)(
            $event, 'Barra intrusa', OperatingUnitKind::Bar
        ));
    })->throws(InvalidOperatingUnitException::class);

    it('hides events and units of one account from another', function (): void {
        $this->context->runAs($this->organizer, function (): void {
            $event = app(CreateEvent::class)('Festival Privado', now()->addWeek(), now()->addWeeks(2));
            app(CreateEventOutlet::class)($event, 'Barra privada', OperatingUnitKind::Bar);
        });
        $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Privada'));

        $this->context->set($this->business);

        expect(Event::count())->toBe(0)
            ->and(OperatingUnit::pluck('name')->all())->toBe(['Sucursal Privada']);
    });

    it('derives the unit type from the event, ignoring what the caller asks for', function (): void {
        $unit = $this->context->runAs($this->business, function () {
            $unit = new OperatingUnit(['name' => 'Intento', 'kind' => OperatingUnitKind::Mixed]);
            $unit->type = OperatingUnitType::EventOutlet;
            $unit->save();

            return $unit;
        });

        expect($unit)->toBeNull();
    })->throws(InvalidOperatingUnitException::class);
});

describe('estructura de la cuenta', function (): void {
    it('separates branches from event outlets in queries', function (): void {
        $this->context->runAs($this->organizer, function (): void {
            $event = app(CreateEvent::class)('Festival Mixto', now()->addWeek(), now()->addWeeks(2));
            app(CreateEventOutlet::class)($event, 'Barra A', OperatingUnitKind::Bar);
        });

        $this->context->set($this->organizer);

        expect(OperatingUnit::query()->eventOutlets()->count())->toBe(1)
            ->and(OperatingUnit::query()->branches()->count())->toBe(0);
    });

    it('knows which world each account belongs to', function (): void {
        expect($this->business->isBusiness())->toBeTrue()
            ->and($this->business->isOrganizer())->toBeFalse()
            ->and($this->organizer->isOrganizer())->toBeTrue();
    });
});
