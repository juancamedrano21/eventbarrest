<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Business\Models\Branch;
use App\Domains\Business\Models\BusinessAccount;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->business = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->organizer = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);
    $this->context = app(TenantContext::class);
});

afterEach(fn () => app(TenantContext::class)->clear());

describe('cada mundo tiene su clase', function (): void {
    it('builds factories as their world class', function (): void {
        expect(BusinessAccount::factory()->create())->toBeInstanceOf(BusinessAccount::class)
            ->and(OrganizerAccount::factory()->create())->toBeInstanceOf(OrganizerAccount::class)
            ->and(Tenant::factory()->create())->toBeInstanceOf(BusinessAccount::class)
            ->and(Tenant::factory()->organizer()->create())->toBeInstanceOf(OrganizerAccount::class);
    });

    it('derives implicit foreign keys from the base class, not the child', function (): void {
        // Sin esto, la pestaña Equipo del admin revienta en cuentas de
        // organizador: users() derivaría organizer_account_id.
        expect($this->organizer->getForeignKey())->toBe('tenant_id')
            ->and($this->business->getForeignKey())->toBe('tenant_id')
            ->and($this->organizer->users()->count())->toBe(0);
    });

    it('creates accounts as their world class', function (): void {
        expect($this->business)->toBeInstanceOf(BusinessAccount::class)
            ->and($this->organizer)->toBeInstanceOf(OrganizerAccount::class)
            ->and(Tenant::query()->find($this->business->id))->toBeInstanceOf(BusinessAccount::class)
            ->and(Tenant::query()->find($this->organizer->id))->toBeInstanceOf(OrganizerAccount::class);
    });

    it('creates branches as Branch, without event and in its account', function (): void {
        $branch = $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

        expect($branch)->toBeInstanceOf(Branch::class)
            ->and($branch->type)->toBe(OperatingUnitType::Branch)
            ->and($branch->event_id)->toBeNull()
            ->and($branch->kind)->toBe(OperatingUnitKind::Mixed)
            ->and($branch->tenant_id)->toBe($this->business->id);
    });

    it('creates events with their outlets', function (): void {
        [$event, $bar, $kitchen, $outletCount] = $this->context->runAs($this->organizer, function () {
            $event = app(CreateEvent::class)('Festival del Mar', now()->addWeek(), now()->addWeeks(2), 'Malecón');
            $bar = app(CreateEventOutlet::class)($event, 'Barra principal', OperatingUnitKind::Bar);
            $kitchen = app(CreateEventOutlet::class)($event, 'Cocina', OperatingUnitKind::Kitchen);

            return [$event, $bar, $kitchen, $event->outlets()->count()];
        });

        expect($event->venue)->toBe('Malecón')
            ->and($bar)->toBeInstanceOf(EventOutlet::class)
            ->and($bar->type)->toBe(OperatingUnitType::EventOutlet)
            ->and($bar->kind)->toBe(OperatingUnitKind::Bar)
            ->and($bar->event_id)->toBe($event->id)
            ->and($kitchen->kind)->toBe(OperatingUnitKind::Kitchen)
            ->and($outletCount)->toBe(2);
    });
});

describe('los mundos no se mezclan', function (): void {
    it('refuses an event in a business account', function (): void {
        $this->context->runAs($this->business, fn () => app(CreateEvent::class)(
            'Festival Imposible', now()->addWeek(), now()->addWeeks(2)
        ));
    })->throws(InvalidOperatingUnitException::class);

    it('refuses a branch in an organizer account', function (): void {
        $this->context->runAs($this->organizer, fn () => app(CreateBranch::class)('Sucursal Imposible'));
    })->throws(InvalidOperatingUnitException::class);

    it('refuses an outlet hanging from another accounts event', function (): void {
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
});

describe('consultas por mundo y vista neutral', function (): void {
    beforeEach(function (): void {
        $this->context->runAs($this->organizer, function (): void {
            $event = app(CreateEvent::class)('Festival Mixto', now()->addWeek(), now()->addWeeks(2));
            app(CreateEventOutlet::class)($event, 'Barra A', OperatingUnitKind::Bar);
        });
    });

    it('scopes each world model to its own rows', function (): void {
        $this->context->set($this->organizer);

        expect(EventOutlet::count())->toBe(1)
            ->and(Branch::count())->toBe(0);
    });

    it('hydrates the neutral view with world classes', function (): void {
        $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

        $this->context->clear();
        $all = OperatingUnit::query()->withoutTenancy()->get();

        expect($all)->toHaveCount(2)
            ->and($all->whereInstanceOf(Branch::class))->toHaveCount(1)
            ->and($all->whereInstanceOf(EventOutlet::class))->toHaveCount(1);
    });
});
