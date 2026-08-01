<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comercio;

use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Comercio\Concerns\AuthorizesComercioPanel;
use App\Http\Controllers\Concerns\HandlesVendorCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El catálogo desde la puerta del encargado: misma operación compartida
 * (HandlesVendorCatalog), comercio implícito, permiso del caso.
 */
class CatalogController extends Controller
{
    use AuthorizesComercioPanel;
    use HandlesVendorCatalog;

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->createCategory($request, $this->comercioDe($request, Permission::CatalogManage));

        return back()->with('status', 'Categoría creada en el menú.');
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $this->createProduct($request, $this->comercioDe($request, Permission::CatalogManage));

        return back()->with('status', 'Producto añadido al menú.');
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        $this->changeProduct($request, $this->comercioDe($request, Permission::CatalogManage), $product);

        return back()->with('status', 'Producto actualizado.');
    }

    public function storeRecipeItem(Request $request, int $product): RedirectResponse
    {
        $this->addRecipeItem($request, $this->comercioDe($request, Permission::CatalogManage), $product);

        return back()->with('status', 'Ingrediente añadido a la receta.');
    }

    public function destroyRecipeItem(Request $request, int $product, int $item): RedirectResponse
    {
        $this->removeRecipeItem($this->comercioDe($request, Permission::CatalogManage), $product, $item);

        return back()->with('status', 'Ingrediente retirado de la receta.');
    }
}
