<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Inventory\Actions\AdjustStock;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Actions\TransferStock;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

function stockOf(int $unitId, int $itemId): float
{
    return (float) StockLevel::query()
        ->where('operating_unit_id', $unitId)
        ->where('inventory_item_id', $itemId)
        ->value('quantity');
}

beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->context = app(TenantContext::class);
    $this->context->set($this->tenant);

    $this->branch = app(CreateBranch::class)('Sucursal Centro');
    $this->ron = InventoryItem::factory()->create(['name' => 'Ron blanco', 'cost_cents' => 0]);
});

afterEach(fn () => app(TenantContext::class)->clear());

describe('compras y costo promedio', function (): void {
    it('increases stock and sets the cost on the first purchase', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 80, 'Factura 001');

        expect(stockOf($this->branch->id, $this->ron->id))->toBe(1000.0)
            ->and($this->ron->fresh()->cost_cents)->toBe(80);
    });

    it('recomputes the weighted average on later purchases', function (): void {
        // 1000 ml a RD$0.80 + 1000 ml a RD$1.20 → promedio RD$1.00
        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 80);
        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 120);

        expect($this->ron->fresh()->cost_cents)->toBe(100)
            ->and(stockOf($this->branch->id, $this->ron->id))->toBe(2000.0);
    });

    it('weights the average across every unit of the account', function (): void {
        $otra = app(CreateBranch::class)('Sucursal Norte');

        app(RegisterPurchase::class)($this->branch, $this->ron, 3000, 100);
        app(RegisterPurchase::class)($otra, $this->ron, 1000, 200);

        // (3000×100 + 1000×200) / 4000 = 125
        expect($this->ron->fresh()->cost_cents)->toBe(125);
    });

    it('resets the cost when previous stock was zero or negative', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);
        app(RegisterWaste::class)($this->branch, $this->ron, 150, 'Derrame'); // queda -50

        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 200);

        expect($this->ron->fresh()->cost_cents)->toBe(200);
    });
});

describe('mermas y ajustes', function (): void {
    it('registers waste as a negative movement', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 80);
        $movement = app(RegisterWaste::class)($this->branch, $this->ron, 200, 'Botella rota');

        expect(stockOf($this->branch->id, $this->ron->id))->toBe(800.0)
            ->and($movement->type)->toBe(StockMovementType::Waste)
            ->and((float) $movement->quantity)->toBe(-200.0)
            ->and($movement->reference)->toBe('Botella rota');
    });

    it('adjusts with either sign after a physical count', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 500, 80);

        app(AdjustStock::class)($this->branch, $this->ron, -30, 'Conteo semanal');
        app(AdjustStock::class)($this->branch, $this->ron, 10, 'Corrección');

        expect(stockOf($this->branch->id, $this->ron->id))->toBe(480.0);
    });

    it('lets stock go negative instead of blocking, as the POS will need', function (): void {
        app(RegisterWaste::class)($this->branch, $this->ron, 50, 'Sin stock previo');

        $level = StockLevel::query()->sole();

        expect((float) $level->quantity)->toBe(-50.0)
            ->and($level->isLow())->toBeFalse(); // sin umbral no hay alerta
    });

    it('flags the row when stock falls to the alert threshold', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);
        StockLevel::query()->sole()->update(['alert_threshold' => 90]);

        app(RegisterWaste::class)($this->branch, $this->ron, 15, 'Consumo');

        expect(StockLevel::query()->sole()->isLow())->toBeTrue();
    });
});

describe('traslados', function (): void {
    it('moves stock between two units atomically with a shared reference', function (): void {
        $otra = app(CreateBranch::class)('Sucursal Norte');
        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 80);

        $reference = app(TransferStock::class)($this->branch, $otra, $this->ron, 400);

        expect(stockOf($this->branch->id, $this->ron->id))->toBe(600.0)
            ->and(stockOf($otra->id, $this->ron->id))->toBe(400.0)
            ->and(StockMovement::query()->where('reference', $reference)->count())->toBe(2);
    });

    it('refuses to transfer a unit onto itself', function (): void {
        app(TransferStock::class)($this->branch, $this->branch, $this->ron, 10);
    })->throws(InventoryException::class);
});

describe('la proyección también está blindada', function (): void {
    it('refuses to write quantity by hand on an instance', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        $level = StockLevel::query()->sole();
        $level->quantity = '999.000';
        $level->save();
    })->throws(InventoryException::class);

    it('refuses mass updates of quantity and deletes of the projection', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        expect(fn () => StockLevel::query()->update(['quantity' => 999]))
            ->toThrow(InventoryException::class)
            ->and(fn () => StockLevel::query()->sole()->delete())
            ->toThrow(InventoryException::class);
    });

    it('still allows editing the alert threshold', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        StockLevel::query()->sole()->update(['alert_threshold' => 25]);

        expect((float) StockLevel::query()->sole()->alert_threshold)->toBe(25.0);
    });
});

describe('el libro es inmutable', function (): void {
    it('refuses to edit a movement', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        StockMovement::query()->sole()->update(['quantity' => 999]);
    })->throws(InventoryException::class);

    it('refuses to delete a movement', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        StockMovement::query()->sole()->delete();
    })->throws(InventoryException::class);

    it('refuses mass updates and mass deletes on the ledger', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        expect(fn () => StockMovement::query()->update(['quantity' => 999]))
            ->toThrow(InventoryException::class)
            ->and(fn () => StockMovement::query()->delete())
            ->toThrow(InventoryException::class);
    });
});

describe('validaciones del movimiento', function (): void {
    it('refuses zero quantities', function (): void {
        app(StockLedger::class)->apply($this->branch, $this->ron, StockMovementType::Adjustment, 0);
    })->throws(InventoryException::class);

    it('refuses a purchase with negative quantity through the ledger', function (): void {
        app(StockLedger::class)->apply($this->branch, $this->ron, StockMovementType::Purchase, -5, 80);
    })->throws(InventoryException::class);

    it('refuses movements on another accounts unit', function (): void {
        $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
        $unidadAjena = $this->context->runAs($otro, fn () => app(CreateBranch::class)('Ajena'));

        app(RegisterPurchase::class)($unidadAjena, $this->ron, 10, 80);
    })->throws(InventoryException::class);

    it('refuses movements on another accounts item', function (): void {
        $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
        $insumoAjeno = $this->context->runAs($otro, fn () => InventoryItem::factory()->create());

        app(RegisterPurchase::class)($this->branch, $insumoAjeno, 10, 80);
    })->throws(InventoryException::class);

    it('keeps the ledger of one account invisible to another', function (): void {
        app(RegisterPurchase::class)($this->branch, $this->ron, 100, 80);

        $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
        $this->context->set($otro);

        expect(StockMovement::count())->toBe(0)
            ->and(StockLevel::count())->toBe(0);
    });
});
