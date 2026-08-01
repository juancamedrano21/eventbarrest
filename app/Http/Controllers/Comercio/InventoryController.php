<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comercio;

use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Comercio\Concerns\AuthorizesComercioPanel;
use App\Http\Controllers\Concerns\HandlesVendorInventory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El inventario desde la puerta del encargado: misma operación compartida
 * (HandlesVendorInventory), comercio implícito, permiso del caso.
 */
class InventoryController extends Controller
{
    use AuthorizesComercioPanel;
    use HandlesVendorInventory;

    public function storeItem(Request $request): RedirectResponse
    {
        $this->createItem($request, $this->comercioDe($request, Permission::CatalogManage));

        return back()->with('status', 'Insumo creado.');
    }

    public function storePurchase(Request $request): RedirectResponse
    {
        $this->registerPurchase($request, $this->comercioDe($request, Permission::InventoryManage));

        return back()->with('status', 'Compra registrada: el stock y el costo promedio ya la reflejan.');
    }
}
