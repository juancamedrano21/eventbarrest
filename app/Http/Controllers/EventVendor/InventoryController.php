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
}
