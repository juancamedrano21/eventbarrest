<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Operations\Models\OperatingUnit;

/**
 * Merma: rotura, caducidad, derrame. Siempre resta, y el motivo queda en el
 * libro para la reportería de pérdidas.
 */
class RegisterWaste
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        OperatingUnit $unit,
        InventoryItem $item,
        float $quantity,
        ?string $reason = null,
    ): StockMovement {
        return $this->ledger->apply($unit, $item, StockMovementType::Waste, -abs($quantity), null, $reason);
    }
}
