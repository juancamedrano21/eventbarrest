<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Concerns\HandlesVendorCatalog;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El dueño de la cuenta también OPERA dentro del comercio (decisión
 * 2026-08-01, revierte el «solo lectura»): gestiona su catálogo desde el
 * perfil. La operación vive en HandlesVendorCatalog, compartida con la
 * puerta /comercio del encargado.
 */
class VendorCatalogController extends Controller
{
    use AuthorizesOrganizerPanel;
    use HandlesVendorCatalog;

    public function storeCategory(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $this->createCategory($request, Vendor::query()->findOrFail($vendor));

        return back()->with('status', 'Categoría creada en el menú.');
    }

    public function storeProduct(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $this->createProduct($request, Vendor::query()->findOrFail($vendor));

        return back()->with('status', 'Producto añadido al menú.');
    }

    public function storeRecipeItem(Request $request, int $vendor, int $product): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $this->addRecipeItem($request, Vendor::query()->findOrFail($vendor), $product);

        return back()->with('status', 'Ingrediente añadido a la receta.');
    }

    public function destroyRecipeItem(Request $request, int $vendor, int $product, int $item): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $this->removeRecipeItem(Vendor::query()->findOrFail($vendor), $product, $item);

        return back()->with('status', 'Ingrediente retirado de la receta.');
    }

    public function updateProduct(Request $request, int $vendor, int $product): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $this->changeProduct($request, Vendor::query()->findOrFail($vendor), $product);

        return back()->with('status', 'Producto actualizado.');
    }
}
