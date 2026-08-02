<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Actions\AdjustStock;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Actions\TransferStock;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las operaciones de inventario del comercio, UNA sola vez para las dos
 * puertas: insumos, compras, conteos, mermas, traslados y umbrales, siempre
 * COMO el comercio y siempre por el libro mayor.
 */
trait HandlesVendorInventory
{
    protected function createItem(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('inventory_items', 'name')
                    ->where('tenant_id', $record->tenant_id)
                    ->where('vendor_id', $record->id)],
            'base_unit' => ['required', 'in:ml,g,unidad'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'name.unique' => 'Ya existe un insumo con ese nombre en este comercio.',
        ], ['name' => 'nombre', 'cost' => 'costo']);

        app(VendorContext::class)->runAs($record, fn () => InventoryItem::create([
            'name' => $data['name'],
            'base_unit' => MeasurementUnit::from($data['base_unit']),
            'cost_cents' => (int) round(((float) ($data['cost'] ?? 0)) * 100),
        ]));
    }

    protected function registerPurchase(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            'operating_unit_id' => ['required', 'integer'],
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        // La unidad debe ser DE este comercio; el insumo lo resuelve el
        // scope con el comercio activo, y el guard del ledger remata.
        $unit = OperatingUnit::query()
            ->where('vendor_id', $record->id)
            ->findOrFail((int) $data['operating_unit_id']);

        app(VendorContext::class)->runAs($record, function () use ($unit, $data): void {
            app(RegisterPurchase::class)(
                $unit,
                InventoryItem::query()->findOrFail((int) $data['inventory_item_id']),
                (float) $data['quantity'],
                (int) round(((float) $data['unit_cost']) * 100),
                $data['reference'] ?? null,
            );
        });
    }

    /**
     * Conteo físico: la cantidad va CON SIGNO — lo contado menos lo que dice
     * el sistema. Positivo si aparece más de lo esperado.
     */
    protected function adjustStock(Request $request, Vendor $record): void
    {
        $data = $this->validaMovimiento($request, [
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $unit = $this->unidadDe($record, (int) $data['operating_unit_id']);

        app(VendorContext::class)->runAs($record, fn () => app(AdjustStock::class)(
            $unit,
            InventoryItem::query()->findOrFail((int) $data['inventory_item_id']),
            (float) $data['quantity'],
            $data['reason'],
        ));
    }

    /** Lo que se rompió, se derramó o se venció. Siempre resta. */
    protected function registerWaste(Request $request, Vendor $record): void
    {
        $data = $this->validaMovimiento($request, [
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $unit = $this->unidadDe($record, (int) $data['operating_unit_id']);

        app(VendorContext::class)->runAs($record, fn () => app(RegisterWaste::class)(
            $unit,
            InventoryItem::query()->findOrFail((int) $data['inventory_item_id']),
            (float) $data['quantity'],
            $data['reason'],
        ));
    }

    /** Mover stock entre puestos DEL MISMO comercio. */
    protected function transferStock(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            'from_unit_id' => ['required', 'integer'],
            'to_unit_id' => ['required', 'integer', 'different:from_unit_id'],
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ], [
            'to_unit_id.different' => 'El origen y el destino deben ser puestos distintos.',
        ], ['quantity' => 'cantidad']);

        $origen = $this->unidadDe($record, (int) $data['from_unit_id']);
        $destino = $this->unidadDe($record, (int) $data['to_unit_id']);

        app(VendorContext::class)->runAs($record, fn () => app(TransferStock::class)(
            $origen,
            $destino,
            InventoryItem::query()->findOrFail((int) $data['inventory_item_id']),
            (float) $data['quantity'],
        ));
    }

    /**
     * El mínimo que enciende el aviso de reponer. No mueve stock.
     *
     * Sin esta pantalla el badge «Bajo mínimo» era decorado: `isLow()` es
     * falso mientras el umbral sea nulo, y nada podía fijarlo.
     */
    protected function changeThreshold(Request $request, Vendor $record, int $level): void
    {
        $data = $request->validate([
            'alert_threshold' => ['required', 'numeric', 'min:0'],
        ], [], ['alert_threshold' => 'umbral']);

        app(VendorContext::class)->runAs($record, function () use ($record, $level, $data): void {
            // StockLevel no lleva vendor_id: se acota por su unidad.
            StockLevel::query()
                ->whereHas('operatingUnit', fn ($q) => $q->where('vendor_id', $record->id))
                ->findOrFail($level)
                ->update(['alert_threshold' => round((float) $data['alert_threshold'], 3)]);
        });
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
        ], [], ['quantity' => 'cantidad', 'reason' => 'motivo']);
    }

    /**
     * Un puesto DE ESTE comercio. OperatingUnit no lleva VendorScope, así que
     * la frontera se pone a mano — igual que en la compra.
     */
    private function unidadDe(Vendor $record, int $id): OperatingUnit
    {
        return OperatingUnit::query()
            ->where('vendor_id', $record->id)
            ->findOrFail($id);
    }
}
