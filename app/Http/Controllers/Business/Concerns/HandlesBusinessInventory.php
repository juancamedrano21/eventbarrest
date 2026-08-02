<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business\Concerns;

use App\Domains\Business\Models\Branch;
use App\Domains\Inventory\Actions\AdjustStock;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Actions\TransferStock;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El inventario del negocio. Los insumos son de la cuenta; las existencias,
 * de cada sucursal — por eso todo lo que mueve stock pide una sucursal y
 * nada más la pide el catálogo.
 *
 * Ninguna cantidad se escribe a mano: cada movimiento pasa por su Action y
 * por el libro mayor, que toma los locks en orden y deja el rastro de quién
 * lo hizo. `stock_movements` es inmutable: corregir es otro movimiento.
 */
trait HandlesBusinessInventory
{
    protected function createItem(Request $request, int $tenantId): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('inventory_items', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')],
            'base_unit' => ['required', 'in:ml,g,unidad'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'name.unique' => 'Ya existe un insumo con ese nombre.',
        ], ['name' => 'nombre', 'cost' => 'costo']);

        InventoryItem::create([
            'name' => $data['name'],
            'base_unit' => MeasurementUnit::from($data['base_unit']),
            'cost_cents' => (int) round(((float) ($data['cost'] ?? 0)) * 100),
        ]);
    }

    /**
     * Corregir el nombre o el costo de un insumo. El costo también lo
     * recalcula cada compra (promedio ponderado); esto es para el arranque
     * y para enmendar un dato mal escrito.
     */
    protected function changeItem(Request $request, int $tenantId, InventoryItem $item): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('inventory_items', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')
                    ->ignore($item->id)],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'name.unique' => 'Ya existe un insumo con ese nombre.',
        ], ['name' => 'nombre', 'cost' => 'costo']);

        // La unidad base no se toca: recetas, compras y movimientos ya
        // hablan en ella, y cambiarla reinterpretaría el histórico entero.
        $item->update([
            'name' => $data['name'],
            'cost_cents' => (int) round(((float) ($data['cost'] ?? 0)) * 100),
        ]);
    }

    protected function registerPurchase(Request $request): void
    {
        $data = $this->validaMovimiento($request, [
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        app(RegisterPurchase::class)(
            $this->sucursal((int) $data['operating_unit_id']),
            $this->insumo((int) $data['inventory_item_id']),
            (float) $data['quantity'],
            (int) round(((float) $data['unit_cost']) * 100),
            $data['reference'] ?? null,
        );
    }

    /**
     * Conteo físico: la cantidad va CON SIGNO — lo contado menos lo que dice
     * el sistema. Positivo si aparece más de lo esperado.
     */
    protected function adjustStock(Request $request): void
    {
        $data = $this->validaMovimiento($request, [
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        app(AdjustStock::class)(
            $this->sucursal((int) $data['operating_unit_id']),
            $this->insumo((int) $data['inventory_item_id']),
            (float) $data['quantity'],
            $data['reason'],
        );
    }

    /** Lo que se rompió, se derramó o se venció. Siempre resta. */
    protected function registerWaste(Request $request): void
    {
        $data = $this->validaMovimiento($request, [
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        app(RegisterWaste::class)(
            $this->sucursal((int) $data['operating_unit_id']),
            $this->insumo((int) $data['inventory_item_id']),
            (float) $data['quantity'],
            $data['reason'],
        );
    }

    /** Mover stock de una sucursal a otra: sale de una y entra en la otra. */
    protected function transferStock(Request $request): void
    {
        $data = $request->validate([
            'from_unit_id' => ['required', 'integer'],
            'to_unit_id' => ['required', 'integer', 'different:from_unit_id'],
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ], [
            'to_unit_id.different' => 'El origen y el destino deben ser sucursales distintas.',
        ], ['quantity' => 'cantidad']);

        app(TransferStock::class)(
            $this->sucursal((int) $data['from_unit_id']),
            $this->sucursal((int) $data['to_unit_id']),
            $this->insumo((int) $data['inventory_item_id']),
            (float) $data['quantity'],
        );
    }

    /** El mínimo que enciende el aviso de reponer. No mueve stock. */
    protected function changeThreshold(Request $request, StockLevel $level): void
    {
        $data = $request->validate([
            'alert_threshold' => ['required', 'numeric', 'min:0'],
        ], [], ['alert_threshold' => 'umbral']);

        $level->update(['alert_threshold' => round((float) $data['alert_threshold'], 3)]);
    }

    /**
     * @param  array<string, array<int, string>>  $extra
     * @return array<string, mixed>
     */
    private function validaMovimiento(Request $request, array $extra): array
    {
        return $request->validate([
            'operating_unit_id' => ['required', 'integer'],
            'inventory_item_id' => ['required', 'integer'],
            ...$extra,
        ], [], ['quantity' => 'cantidad', 'reason' => 'motivo', 'unit_cost' => 'costo unitario']);
    }

    /**
     * Sucursal de ESTA cuenta. Branch::query() trae el scope del tenant y el
     * de herencia, así que un puesto de evento o una sucursal ajena dan 404.
     */
    private function sucursal(int $id): Branch
    {
        return Branch::query()->findOrFail($id);
    }

    private function insumo(int $id): InventoryItem
    {
        return InventoryItem::query()->findOrFail($id);
    }
}
