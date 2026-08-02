<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Queries;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;
use stdClass;

/**
 * El cuadre de mercancía de un evento, insumo a insumo y puesto a puesto:
 *
 *     asignado + comprado − vendido − mermado − devuelto = lo que falta
 *
 * Es la otra mitad de la conversación al cerrar un festival. La liquidación
 * dice cuánto dinero entró; esto dice si la mercancía que se entregó aparece.
 * Un faltante grande no es un error del sistema: es la pregunta que hay que
 * hacerle a alguien.
 *
 * El consumo por ventas no se estima: sale de los movimientos que las propias
 * recetas escribieron al cobrar, así que un mojito descuenta su ron y su
 * limón sin que nadie los cuente a mano.
 */
class EventStockReconciliation
{
    /**
     * @return Collection<int, EventStockLine>
     */
    public function forEvent(Event $event): Collection
    {
        $puestos = EventOutlet::query()
            ->where('event_id', $event->id)
            ->with('vendor')
            ->get();

        if ($puestos->isEmpty()) {
            return collect();
        }

        // Un SUM por tipo de movimiento, en una sola pasada. Traerse los
        // movimientos uno a uno sería una consulta por insumo y por puesto.
        $filas = StockMovement::query()
            ->join('inventory_items as i', 'i.id', '=', 'stock_movements.inventory_item_id')
            ->whereIn('stock_movements.operating_unit_id', $puestos->pluck('id'))
            ->selectRaw(
                'stock_movements.operating_unit_id as unit_id, '
                .'stock_movements.inventory_item_id as item_id, '
                .'i.name as item_name, i.base_unit as base_unit, '
                .$this->sumOf(StockMovementType::EventAllocation).' as asignado, '
                .$this->sumOf(StockMovementType::Purchase).' as comprado, '
                .$this->sumOf(StockMovementType::SaleConsumption).' as vendido, '
                .$this->sumOf(StockMovementType::Waste).' as mermado, '
                .$this->sumOf(StockMovementType::EventReturn).' as devuelto, '
                .$this->sumOf(StockMovementType::Adjustment).' as ajustado, '
                .$this->sumOf(StockMovementType::TransferIn).' as recibido, '
                .$this->sumOf(StockMovementType::TransferOut).' as enviado'
            )
            ->groupBy('stock_movements.operating_unit_id', 'stock_movements.inventory_item_id', 'i.name', 'i.base_unit')
            ->toBase()
            ->get();

        $porId = $puestos->keyBy('id');

        return $filas
            ->map(function (stdClass $fila) use ($porId): EventStockLine {
                // El puesto existe seguro: las filas salen de sus propios
                // movimientos, y la consulta ya se acotó a los del evento.
                $puesto = $porId->get((int) $fila->unit_id);

                return EventStockLine::from(
                    outletId: (int) $fila->unit_id,
                    outletName: (string) $puesto->name,
                    vendorName: (string) $puesto->vendor->name,
                    itemId: (int) $fila->item_id,
                    itemName: (string) $fila->item_name,
                    baseUnit: (string) $fila->base_unit,
                    allocated: (float) $fila->asignado,
                    purchased: (float) $fila->comprado,
                    sold: (float) $fila->vendido,
                    wasted: (float) $fila->mermado,
                    returned: (float) $fila->devuelto,
                    adjusted: (float) $fila->ajustado,
                    transferredIn: (float) $fila->recibido,
                    transferredOut: (float) $fila->enviado,
                );
            })
            // Lo que más falta, primero: es lo que hay que mirar.
            ->sortByDesc(fn (EventStockLine $linea): float => abs($linea->missing))
            ->values();
    }

    /**
     * La suma de un tipo, en positivo. Las cantidades viven con signo en el
     * libro mayor —salidas en negativo—, y para leer «se vendieron 40» hay
     * que darle la vuelta a las que restan.
     */
    private function sumOf(StockMovementType $type): string
    {
        $signo = $type->direction() < 0 ? '-1' : '1';

        return "COALESCE(SUM(CASE WHEN stock_movements.type = '{$type->value}' "
            ."THEN stock_movements.quantity * {$signo} ELSE 0 END), 0)";
    }
}
