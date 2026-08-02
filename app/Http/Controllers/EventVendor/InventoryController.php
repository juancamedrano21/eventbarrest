<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventVendor;

use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Concerns\HandlesVendorInventory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventVendor\Concerns\AuthorizesEventVendorPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El inventario desde la puerta del encargado: misma operación compartida
 * (HandlesVendorInventory), comercio implícito, permiso del caso.
 */
class InventoryController extends Controller
{
    use AuthorizesEventVendorPanel;
    use HandlesVendorInventory;

    public function storeItem(Request $request): RedirectResponse
    {
        // El alta de insumos es inventario, no carta: así Almacén puede dar
        // de alta lo que compra (misma regla en las dos puertas).
        $this->createItem($request, $this->comercioDe($request, Permission::InventoryManage));

        return back()->with('status', 'Insumo creado.');
    }

    public function storePurchase(Request $request): RedirectResponse
    {
        $this->registerPurchase($request, $this->comercioDe($request, Permission::InventoryManage));

        return back()->with('status', 'Compra registrada: el stock y el costo promedio ya la reflejan.');
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $this->adjustStock($request, $this->comercioDe($request, Permission::InventoryAdjust));

        return back()->with('status', 'Ajuste registrado.');
    }

    public function storeWaste(Request $request): RedirectResponse
    {
        $this->registerWaste($request, $this->comercioDe($request, Permission::InventoryAdjust));

        return back()->with('status', 'Merma registrada.');
    }

    public function storeTransfer(Request $request): RedirectResponse
    {
        $this->transferStock($request, $this->comercioDe($request, Permission::InventoryTransfer));

        return back()->with('status', 'Traslado registrado en los dos puestos.');
    }

    public function updateThreshold(Request $request, int $level): RedirectResponse
    {
        $this->changeThreshold($request, $this->comercioDe($request, Permission::InventoryManage), $level);

        return back()->with('status', 'Umbral de alerta actualizado.');
    }
}
