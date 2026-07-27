<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Operations\Models\OperatingUnit;

/**
 * Ajuste tras conteo físico: la diferencia entre lo que el sistema creía y
 * lo que hay de verdad, con signo. El conteo físico manda.
 */
class AdjustStock
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        OperatingUnit $unit,
        InventoryItem $item,
        float $signedQuantity,
        ?string $reason = null,
    ): StockMovement {
        return $this->ledger->apply($unit, $item, StockMovementType::Adjustment, $signedQuantity, null, $reason);
    }
}
