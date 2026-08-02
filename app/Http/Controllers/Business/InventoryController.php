<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Business\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Business\Concerns\HandlesBusinessInventory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El inventario del negocio: qué hay en cada sucursal, qué entró y qué
 * salió.
 *
 * Cada operación pide su propio permiso: comprar y registrar insumos es
 * inventory.manage; contar, mermar y trasladar son permisos aparte porque
 * corrigen el stock sin que haya pasado nada por la caja.
 */
class InventoryController extends Controller
{
    use AuthorizesBusinessPanel;
    use HandlesBusinessInventory;

    public function index(Request $request): View
    {
        $this->negocioDe($request, Permission::InventoryManage->value);

        $sucursal = $request->integer('sucursal') ?: null;

        return view('business.inventory', [
            'existencias' => StockLevel::query()
                ->when($sucursal !== null, fn ($q) => $q->where('operating_unit_id', $sucursal))
                ->with(['operatingUnit', 'inventoryItem'])
                ->get()
                ->sortBy([
                    fn (StockLevel $a, StockLevel $b): int => $a->operatingUnit->name <=> $b->operatingUnit->name,
                    fn (StockLevel $a, StockLevel $b): int => $a->inventoryItem->name <=> $b->inventoryItem->name,
                ])
                ->values(),
            'movimientos' => StockMovement::query()
                ->when($sucursal !== null, fn ($q) => $q->where('operating_unit_id', $sucursal))
                ->with(['operatingUnit', 'inventoryItem', 'user'])
                ->latest('id')
                ->limit(60)
                ->get(),
            'insumos' => InventoryItem::query()->orderBy('name')->get(),
            'sucursales' => Branch::query()
                ->where('status', OperatingUnitStatus::Active->value)
                ->orderBy('name')
                ->get(),
            'sucursalFiltrada' => $sucursal,
            'puede' => [
                'ajustar' => (bool) $request->user()?->can(Permission::InventoryAdjust->value),
                'trasladar' => (bool) $request->user()?->can(Permission::InventoryTransfer->value),
            ],
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::InventoryManage->value);
        $this->createItem($request, (int) $negocio->id);

        return back()->with('status', 'Insumo creado.');
    }

    public function updateItem(Request $request, int $item): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::InventoryManage->value);
        $this->changeItem($request, (int) $negocio->id, InventoryItem::query()->findOrFail($item));

        return back()->with('status', 'Insumo actualizado.');
    }

    public function storePurchase(Request $request): RedirectResponse
    {
        $this->negocioDe($request, Permission::InventoryManage->value);
        $this->registerPurchase($request);

        return back()->with('status', 'Compra registrada: el stock y el costo promedio ya la reflejan.');
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $this->negocioDe($request, Permission::InventoryAdjust->value);
        $this->adjustStock($request);

        return back()->with('status', 'Ajuste registrado.');
    }

    public function storeWaste(Request $request): RedirectResponse
    {
        $this->negocioDe($request, Permission::InventoryAdjust->value);
        $this->registerWaste($request);

        return back()->with('status', 'Merma registrada.');
    }

    public function storeTransfer(Request $request): RedirectResponse
    {
        $this->negocioDe($request, Permission::InventoryTransfer->value);
        $this->transferStock($request);

        return back()->with('status', 'Traslado registrado en las dos sucursales.');
    }

    public function updateThreshold(Request $request, int $level): RedirectResponse
    {
        $this->negocioDe($request, Permission::InventoryManage->value);
        $this->changeThreshold($request, StockLevel::query()->findOrFail($level));

        return back()->with('status', 'Umbral de alerta actualizado.');
    }
}
