<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Operations\Models\OperatingUnit;

/**
 * Recepción de mercancía: sube existencias y recalcula el costo promedio
 * ponderado del insumo con lo pagado en esta compra.
 */
class RegisterPurchase
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        OperatingUnit $unit,
        InventoryItem $item,
        float $quantity,
        int $unitCostCents,
        ?string $reference = null,
    ): StockMovement {
        if ($unitCostCents < 0) {
            throw InventoryException::purchaseNeedsUnitCost();
        }

        return $this->ledger->apply($unit, $item, StockMovementType::Purchase, abs($quantity), $unitCostCents, $reference);
    }
}
