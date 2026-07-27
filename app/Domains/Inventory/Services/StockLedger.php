<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta de escritura del inventario. Cada movimiento se registra
 * en el libro (inmutable) y actualiza la proyección de existencias en la
 * misma transacción, con lock de fila: dos cajas vendiendo el mismo insumo
 * a la vez no pierden unidades.
 *
 * Orden de locks (auditado contra deadlocks reales en MySQL 8):
 *   1. Si es compra, el X del insumo PRIMERO — el INSERT del movimiento toma
 *      un lock S sobre esa fila por la FK, y pedir el X después provoca el
 *      upgrade S→X que InnoDB resuelve matando una de las dos compras.
 *   2. El movimiento (INSERT).
 *   3. El X de la fila de existencias.
 *   4. La suma para el costo promedio va con lock: las lecturas bloqueantes
 *      leen lo último commiteado, no la snapshot vieja de la transacción.
 * Y attempts=3: un deadlock residual se reintenta entero, no revienta en 500.
 *
 * El stock puede quedar negativo a propósito: una venta ya cobrada nunca se
 * bloquea por descuadre de inventario — el conteo físico manda (doc 05).
 */
class StockLedger
{
    private const ATTEMPTS = 3;

    public function apply(
        OperatingUnit $unit,
        InventoryItem $item,
        StockMovementType $type,
        float $quantity,
        ?int $unitCostCents = null,
        ?string $reference = null,
    ): StockMovement {
        return DB::transaction(function () use ($unit, $item, $type, $quantity, $unitCostCents, $reference): StockMovement {
            $lockedItem = null;

            if ($type === StockMovementType::Purchase && $unitCostCents !== null) {
                // Sin scope a propósito: si el insumo es de otra cuenta, el
                // guard del movimiento lo dirá con la excepción de dominio.
                /** @var InventoryItem $lockedItem */
                $lockedItem = InventoryItem::query()->withoutTenancy()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            }

            $movement = StockMovement::create([
                'operating_unit_id' => $unit->id,
                'inventory_item_id' => $item->id,
                'type' => $type,
                'quantity' => number_format($quantity, 3, '.', ''),
                'unit_cost_cents' => $unitCostCents,
                'reference' => $reference,
                'user_id' => Auth::id(),
            ]);

            $level = StockLevel::query()
                ->where('operating_unit_id', $unit->id)
                ->where('inventory_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if ($level === null) {
                $level = new StockLevel;
                $level->operating_unit_id = $unit->id;
                $level->inventory_item_id = $item->id;
            }

            $level->quantity = number_format((float) $level->quantity + $quantity, 3, '.', '');
            $level->saveProjection();

            // $lockedItem solo existe cuando hubo costo unitario de compra.
            if ($lockedItem !== null) {
                $this->recomputeAverageCost($lockedItem, $quantity, (int) $unitCostCents);
                $item->cost_cents = $lockedItem->cost_cents;
            }

            return $movement;
        }, self::ATTEMPTS);
    }

    /**
     * Costo promedio ponderado contra el stock total de la cuenta. Recibe el
     * insumo YA bloqueado (X) y suma las existencias con lock, para leer lo
     * último commiteado y no la snapshot de esta transacción. Se llama
     * DESPUÉS de sumar la compra, así que el total ya la incluye. Si no
     * había stock previo (o era negativo), el costo pasa a ser el de esta
     * compra.
     */
    private function recomputeAverageCost(InventoryItem $lockedItem, float $purchasedQty, int $unitCostCents): void
    {
        $totalQty = (float) StockLevel::query()
            ->where('inventory_item_id', $lockedItem->id)
            ->lockForUpdate()
            ->sum('quantity');

        $previousQty = $totalQty - $purchasedQty;

        $lockedItem->cost_cents = $previousQty <= 0 || $totalQty <= 0
            ? $unitCostCents
            : (int) round(($previousQty * $lockedItem->cost_cents + $purchasedQty * $unitCostCents) / $totalQty);

        $lockedItem->save();
    }
}
