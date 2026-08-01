<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Models\OperatingUnit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inventario del comercio operado por el dueño de la cuenta desde el
 * perfil: alta de insumos y registro de compras — por el libro mayor, como
 * todo, y siempre COMO el comercio.
 */
class VendorInventoryController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function storeItem(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

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

        return back()->with('status', 'Insumo creado.');
    }

    public function storePurchase(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::InventoryManage);

        $record = Vendor::query()->findOrFail($vendor);

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

        return back()->with('status', 'Compra registrada: el stock y el costo promedio ya la reflejan.');
    }
}
