<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Concerns\HandlesVendorInventory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inventario del comercio operado por el dueño de la cuenta desde el
 * perfil. La operación vive en HandlesVendorInventory, compartida con la
 * puerta /comercio del encargado.
 */
class VendorInventoryController extends Controller
{
    use AuthorizesOrganizerPanel;
    use HandlesVendorInventory;

    public function storeItem(Request $request, int $vendor): RedirectResponse
    {
        // El alta de insumos es inventario, no carta: así Almacén puede dar
        // de alta lo que compra (misma regla en las dos puertas).
        $this->authorizeOrganizer($request, Permission::InventoryManage);

        $this->createItem($request, Vendor::query()->findOrFail($vendor));

        return back()->with('status', 'Insumo creado.');
    }

    public function storePurchase(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::InventoryManage);

        $this->registerPurchase($request, Vendor::query()->findOrFail($vendor));

        return back()->with('status', 'Compra registrada: el stock y el costo promedio ya la reflejan.');
    }
}
