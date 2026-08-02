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
 * Lo que se le ENTREGA a un puesto para el evento: las cien cervezas que
 * bajan del camión a la barra.
 *
 * Es la entrada que hace posible el cuadre del cierre —asignado, vendido,
 * mermado, devuelto y lo que falta—, y el motivo por el que existe un tipo
 * de movimiento propio en vez de un traslado a secas: un traslado dice que
 * el stock cambió de sitio; una asignación dice que alguien se hizo
 * responsable de esa mercancía para este festival.
 *
 * Si la mercancía sale de otra unidad del mismo comercio, se descuenta allí
 * en la misma transacción. Si viene de fuera del sistema —del almacén de
 * siempre, de una compra que nadie registró—, entra sin origen: dejarla
 * fuera del cuadre por no tener de dónde restarla sería peor.
 */
class AllocateToEvent
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(
        EventOutlet $outlet,
        InventoryItem $item,
        float $quantity,
        ?OperatingUnit $from = null,
    ): string {
        if ($quantity <= 0) {
            throw InventoryException::allocationNeedsQuantity();
        }

        if ($from !== null) {
            if ($from->is($outlet)) {
                throw InventoryException::allocationNeedsTwoUnits();
            }

            // El stock de un comercio no se le entrega a otro: cada uno
            // responde de lo suyo.
            if ($from->getAttribute('vendor_id') !== $outlet->getAttribute('vendor_id')) {
                throw InventoryException::transferAcrossVendors();
            }
        }

        $reference = 'asignacion-'.Str::lower((string) Str::ulid());

        $legs = [[$outlet, StockMovementType::EventAllocation, abs($quantity)]];

        if ($from !== null) {
            $legs[] = [$from, StockMovementType::TransferOut, -abs($quantity)];
        }

        // En orden de id de unidad, como el traslado: dos movimientos
        // cruzados toman los locks igual y no pueden abrazarse.
        usort($legs, fn (array $a, array $b): int => $a[0]->id <=> $b[0]->id);

        DB::transaction(function () use ($legs, $item, $reference): void {
            foreach ($legs as [$unit, $type, $signedQty]) {
                $this->ledger->apply($unit, $item, $type, $signedQty, null, $reference);
            }
        }, 3);

        return $reference;
    }
}
