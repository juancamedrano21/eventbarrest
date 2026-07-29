<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Business\Models\Branch;
use App\Domains\Business\Models\BusinessAccount;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Exceptions\TenantBaseIsNotCreatableException;
use App\Domains\Platform\Exceptions\TenantTypeIsImmutableException;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Los invariantes de los dos mundos, verificados contra los ataques reales
 * encontrados en auditoría. La separación es estructural (cada mundo tiene
 * sus clases), y estos tests fijan lo que ninguna clase permite.
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

describe('la estructura es de clases, no de datos', function (): void {
    it('refuses to create through the neutral base', function (): void {
        $this->context->runAs($this->business, fn () => OperatingUnit::create([
            'name' => 'Directa', 'kind' => OperatingUnitKind::Mixed,
        ]));
    })->throws(InvalidOperatingUnitException::class);

    it('a branch is a branch no matter what the caller forces', function (): void {
        $branch = $this->context->runAs($this->business, function () {
            $branch = new Branch(['name' => 'Terca', 'kind' => OperatingUnitKind::Mixed]);
            $branch->type = OperatingUnitType::EventOutlet;
            $branch->event_id = $this->rivalEvent->id;
            $branch->save();

            return $branch;
        });

        expect($branch->fresh())
            ->type->toBe(OperatingUnitType::Branch)
            ->event_id->toBeNull();
    });

    it('an outlet cannot exist without an event', function (): void {
        $this->context->runAs($this->organizer, fn () => EventOutlet::create([
            'name' => 'Suelta', 'kind' => OperatingUnitKind::Bar,
        ]));
    })->throws(InvalidOperatingUnitException::class);
});

describe('la unidad no cambia de evento', function (): void {
    it('refuses to move an outlet to another event with save', function (): void {
        $outlet = $this->context->runAs($this->organizer, fn () => outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar
        ));

        $other = $this->context->runAs($this->organizer, fn () => app(CreateEvent::class)(
            'Otro Festival', now()->addMonth(), now()->addMonth()->addDays(2)
        ));

        $this->context->set($this->organizer);
        $outlet->event_id = $other->id;
        $outlet->save();
    })->throws(InvalidOperatingUnitException::class);

    it('refuses to move an outlet with a mass update', function (): void {
        $this->context->runAs($this->organizer, fn () => outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar
        ));

        $this->context->set($this->organizer);
        EventOutlet::query()->update(['event_id' => $this->rivalEvent->id]);
    })->throws(InvalidOperatingUnitException::class);

    it('refuses to rewrite the world discriminator with a mass update', function (): void {
        $this->context->runAs($this->business, fn () => app(CreateBranch::class)('Sucursal Centro'));

        $this->context->set($this->business);
        OperatingUnit::query()->update(['type' => OperatingUnitType::EventOutlet->value]);
    })->throws(InvalidOperatingUnitException::class);

    it('still allows ordinary edits', function (): void {
        $outlet = $this->context->runAs($this->organizer, fn () => outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar
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

    it('refuses to rewrite the account type with a mass update on the base', function (): void {
        Tenant::query()->whereKey($this->business->id)->update(['type' => TenantType::Organizer->value]);
    })->throws(TenantTypeIsImmutableException::class);

    it('refuses to flip a whole world with a mass update on a child', function (): void {
        BusinessAccount::query()->update(['type' => TenantType::Organizer->value]);
    })->throws(TenantTypeIsImmutableException::class);

    it('refuses to create an account through the neutral base', function (): void {
        Tenant::create(['name' => 'Cuenta sin mundo']);
    })->throws(TenantBaseIsNotCreatableException::class);
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

    it('refuses two outlets with the same name in one business', function (): void {
        $this->context->set($this->organizer);
        $vendor = vendorIn($this->event, 'Bar Manolo');
        outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar, $vendor);

        expect(fn () => outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar, $vendor))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('lets two businesses have their own barra principal in one event', function (): void {
        $this->context->set($this->organizer);

        outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar, vendorIn($this->event, 'Bar Manolo'));
        outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar, vendorIn($this->event, 'Pizzería Napoli'));

        expect(DB::table('operating_units')->where('name', 'Barra principal')->count())->toBe(2);
    });

    it('allows the same outlet name in different events', function (): void {
        $this->context->set($this->organizer);
        $other = app(CreateEvent::class)('Otro Festival', now()->addMonth(), now()->addMonth()->addDays(2));

        outletFor($this->event, 'Barra principal', OperatingUnitKind::Bar);
        outletFor($other, 'Barra principal', OperatingUnitKind::Bar);

        expect(DB::table('operating_units')->where('name', 'Barra principal')->count())->toBe(2);
    });
});
