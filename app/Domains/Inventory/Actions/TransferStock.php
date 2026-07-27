<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transferencia entre dos unidades de la misma cuenta: dos movimientos
 * hermanos (salida y entrada) en una sola transacción, unidos por la misma
 * referencia. O pasan los dos, o ninguno.
 */
class TransferStock
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        OperatingUnit $from,
        OperatingUnit $to,
        InventoryItem $item,
        float $quantity,
    ): string {
        if ($from->is($to)) {
            throw InventoryException::transferNeedsTwoUnits();
        }

        $reference = 'traslado-'.Str::lower((string) Str::ulid());

        // Las patas se aplican en orden de id de unidad: dos traslados en
        // direcciones opuestas toman los locks en el mismo orden y no pueden
        // abrazarse en deadlock. Y attempts=3 por si queda alguno residual.
        $legs = [
            [$from, StockMovementType::TransferOut, -abs($quantity)],
            [$to, StockMovementType::TransferIn, abs($quantity)],
        ];

        usort($legs, fn (array $a, array $b): int => $a[0]->id <=> $b[0]->id);

        DB::transaction(function () use ($legs, $item, $reference): void {
            foreach ($legs as [$unit, $type, $signedQty]) {
                $this->ledger->apply($unit, $item, $type, $signedQty, null, $reference);
            }
        }, 3);

        return $reference;
    }
}
