<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Actions;

use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que el puesto DEVUELVE al cerrar: las cervezas que sobraron y vuelven
 * al camión.
 *
 * Es el movimiento inverso de la asignación y la última pieza del cuadre.
 * Con destino, la mercancía vuelve a la unidad de donde salió; sin él,
 * simplemente sale del puesto — que es lo que pasa cuando el comercio se la
 * lleva a un almacén que el sistema no conoce.
 *
 * No se comprueba que devuelva menos de lo que se le asignó: puede tener
 * mercancía de una compra hecha en el propio evento, y el ledger admite
 * negativos a propósito. Lo que no cuadre sale en el reporte como faltante,
 * que es donde tiene que verse.
 */
class ReturnFromEvent
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        EventOutlet $outlet,
        InventoryItem $item,
        float $quantity,
        ?OperatingUnit $to = null,
    ): string {
        if ($quantity <= 0) {
            throw InventoryException::allocationNeedsQuantity();
        }

        if ($to !== null) {
            if ($to->is($outlet)) {
                throw InventoryException::allocationNeedsTwoUnits();
            }

            if ($to->getAttribute('vendor_id') !== $outlet->getAttribute('vendor_id')) {
                throw InventoryException::transferAcrossVendors();
            }
        }

        $reference = 'devolucion-'.Str::lower((string) Str::ulid());

        $legs = [[$outlet, StockMovementType::EventReturn, -abs($quantity)]];

        if ($to !== null) {
            $legs[] = [$to, StockMovementType::TransferIn, abs($quantity)];
        }

        usort($legs, fn (array $a, array $b): int => $a[0]->id <=> $b[0]->id);

        DB::transaction(function () use ($legs, $item, $reference): void {
            foreach ($legs as [$unit, $type, $signedQty]) {
                $this->ledger->apply($unit, $item, $type, $signedQty, null, $reference);
            }
        }, 3);

        return $reference;
    }
}
