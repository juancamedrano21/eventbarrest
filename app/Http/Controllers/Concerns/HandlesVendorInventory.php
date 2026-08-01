<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Http\Request;

/**
 * Las operaciones de inventario del comercio, UNA sola vez para las dos
 * puertas: alta de insumos y compras por el libro mayor, siempre COMO el
 * comercio.
 */
trait HandlesVendorInventory
{
    protected function createItem(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_unit' => ['required', 'in:ml,g,unidad'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

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
}
