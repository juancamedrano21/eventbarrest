<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * El modelo marketplace: dentro de una cuenta de organizador viven los
 * negocios (bares, restaurantes), los eventos, y la decisión de qué negocio
 * va a qué evento. Cada negocio maneja lo suyo por separado.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    $this->business = app(CreateTenant::class)('Bar Independiente', null, TenantType::Business);
    $this->tenants = app(TenantContext::class);
    $this->vendors = app(VendorContext::class);

    $this->tenants->set($this->organizer);
    $this->manolo = app(CreateVendor::class)('Bar Manolo');
    $this->napoli = app(CreateVendor::class)('Pizzería Napoli');
    $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

describe('los negocios viven en la cuenta del organizador', function (): void {
    it('lists the vendors of the account', function (): void {
        expect(Vendor::pluck('name')->all())->toBe(['Bar Manolo', 'Pizzería Napoli']);
    });

    it('refuses vendors in a business account', function (): void {
        $this->tenants->runAs($this->business, fn () => app(CreateVendor::class)('Imposible'));
    })->throws(VendorException::class);

    it('hides the vendors of one account from another', function (): void {
        $otro = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);

        expect($this->tenants->runAs($otro, fn () => Vendor::count()))->toBe(0);
    });
});

describe('participación en eventos', function (): void {
    it('invites a vendor with its commission', function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->manolo, 1000);

        expect($this->event->vendors()->pluck('name')->all())->toBe(['Bar Manolo'])
            ->and($this->event->participations()->sole()->commissionPercent())->toBe(10.0);
    });

    it('updates the commission instead of duplicating on re-invite', function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->manolo, 1000);
        app(InviteVendorToEvent::class)($this->event, $this->manolo, 1500);

        expect(EventVendor::count())->toBe(1)
            ->and($this->event->participations()->sole()->commission_bps)->toBe(1500);
    });

    it('lets one vendor take part in several events', function (): void {
        $otro = app(CreateEvent::class)('Bocao 2027', now()->addYear(), now()->addYear()->addDays(2));

        app(InviteVendorToEvent::class)($this->event, $this->manolo, 1000);
        app(InviteVendorToEvent::class)($otro, $this->manolo, 1200);

        expect($this->manolo->events()->count())->toBe(2);
    });

    it('refuses to invite a vendor of another account', function (): void {
        $otro = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);
        $ajeno = $this->tenants->runAs($otro, fn () => app(CreateVendor::class)('Ajeno'));

        app(InviteVendorToEvent::class)($this->event, $ajeno, 500);
    })->throws(VendorException::class);
});

describe('puntos de venta por negocio', function (): void {
    it('creates an outlet for an invited vendor', function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->manolo);

        $outlet = app(CreateEventOutlet::class)($this->event, $this->manolo, 'Barra Manolo', OperatingUnitKind::Bar);

        expect($outlet->vendor_id)->toBe($this->manolo->id)
            ->and($outlet->event_id)->toBe($this->event->id);
    });

    it('refuses an outlet for a vendor that was not invited', function (): void {
        app(CreateEventOutlet::class)($this->event, $this->napoli, 'Barra intrusa', OperatingUnitKind::Bar);
    })->throws(VendorException::class);

    it('refuses a loose outlet without a vendor', function (): void {
        app(CreateEventOutlet::class)($this->event, new Vendor, 'Suelta', OperatingUnitKind::Bar);
    })->throws(VendorException::class);
});

describe('cada negocio maneja lo suyo', function (): void {
    beforeEach(function (): void {
        foreach ([$this->manolo, $this->napoli] as $vendor) {
            $this->vendors->runAs($vendor, function () use ($vendor): void {
                $cat = Category::create(['name' => 'Carta', 'dispatch' => 'bar']);
                Product::create([
                    'category_id' => $cat->id,
                    'name' => 'Mojito',
                    'type' => 'simple',
                    'price_cents' => $vendor->name === 'Bar Manolo' ? 45000 : 50000,
                ]);
                InventoryItem::create(['name' => 'Ron', 'base_unit' => 'ml', 'cost_cents' => 80]);
            });
        }
    });

    it('shows each vendor only its own catalog', function (): void {
        $manolo = $this->vendors->runAs($this->manolo, fn () => Product::sole());
        $napoli = $this->vendors->runAs($this->napoli, fn () => Product::sole());

        expect($manolo->price_cents)->toBe(45000)
            ->and($napoli->price_cents)->toBe(50000);
    });

    it('lets two vendors have a product with the same name', function (): void {
        expect(Product::where('name', 'Mojito')->count())->toBe(2);
    });

    it('shows the organizer the consolidated view of its account', function (): void {
        expect(Product::count())->toBe(2)
            ->and(InventoryItem::count())->toBe(2);
    });

    it('keeps a business account catalog free of vendors', function (): void {
        $branchProduct = $this->tenants->runAs($this->business, function () {
            app(CreateBranch::class)('Sucursal Centro');
            $cat = Category::create(['name' => 'Carta', 'dispatch' => 'bar']);

            return Product::create([
                'category_id' => $cat->id,
                'name' => 'Mojito',
                'type' => 'simple',
                'price_cents' => 40000,
            ]);
        });

        expect($branchProduct->vendor_id)->toBeNull();
    });
});
