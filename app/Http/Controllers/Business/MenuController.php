<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Business\Concerns\HandlesBusinessCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El menú del negocio: categorías, productos y escandallos.
 *
 * El catálogo es de la CUENTA, no de cada sucursal: un solo «Mojito» a un
 * solo precio para todas. El esquema no admite otra cosa hoy —no hay
 * columna que ate un producto a una sucursal— y una carta por local sería
 * una tabla nueva, no un ajuste de pantalla.
 */
class MenuController extends Controller
{
    use AuthorizesBusinessPanel;
    use HandlesBusinessCatalog;

    public function index(Request $request): View
    {
        $negocio = $this->negocioDe($request, Permission::CatalogManage->value);

        return view('business.menu', [
            'negocio' => $negocio,
            'modoVigente' => app(ResolveItbisMode::class)->forVendor(null, (int) $negocio->id),
            'categorias' => Category::query()
                ->with([
                    'products' => fn ($q) => $q->orderBy('name'),
                    'products.inventoryItem',
                    'products.recipeItems.inventoryItem',
                ])
                ->withCount('products')
                ->orderBy('name')
                ->get(),
            'insumos' => InventoryItem::query()->orderBy('name')->get(),
        ]);
    }

    /*
     * Los identificadores llegan como enteros y se resuelven aquí, no por
     * route model binding: los bindings se sustituyen antes de que
     * SetTenantContext fije la cuenta, y el scope no tendría contra qué
     * acotar. Es la convención de todas las puertas del proyecto.
     */

    public function storeCategory(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::CatalogManage->value);
        $this->createCategory($request, (int) $negocio->id);

        return back()->with('status', 'Categoría creada.');
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::CatalogManage->value);
        $this->changeCategory($request, (int) $negocio->id, Category::query()->findOrFail($category));

        return back()->with('status', 'Categoría actualizada.');
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::CatalogManage->value);
        $this->createProduct($request, (int) $negocio->id);

        return back()->with('status', 'Producto creado.');
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::CatalogManage->value);
        $this->changeProduct($request, (int) $negocio->id, Product::query()->findOrFail($product));

        return back()->with('status', 'Producto actualizado.');
    }

    public function storeRecipeItem(Request $request, int $product): RedirectResponse
    {
        $this->negocioDe($request, Permission::CatalogManage->value);
        $this->addRecipeItem($request, Product::query()->findOrFail($product));

        return back()->with('status', 'Insumo añadido a la receta.');
    }

    public function destroyRecipeItem(Request $request, int $product, int $item): RedirectResponse
    {
        $this->negocioDe($request, Permission::CatalogManage->value);
        $this->removeRecipeItem(Product::query()->findOrFail($product), $item);

        return back()->with('status', 'Insumo quitado de la receta.');
    }
}
