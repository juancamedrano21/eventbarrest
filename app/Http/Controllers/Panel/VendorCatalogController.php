<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Models\InventoryItem;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El dueño de la cuenta también OPERA dentro del comercio (decisión
 * 2026-08-01, revierte el «solo lectura»): gestiona su catálogo desde el
 * perfil. Todo corre COMO el comercio (runAs): las filas nacen con su
 * vendor_id y los guards de aislamiento siguen mandando.
 */
class VendorCatalogController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function storeCategory(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'in:alimentos,bebidas'],
        ]);

        app(VendorContext::class)->runAs($record, fn () => Category::create([
            'name' => $data['name'],
            // La clasificación del menú ES el despacho: Alimentos salen de
            // cocina, Bebidas de barra — el POS y las comandas ya lo usan.
            'dispatch' => $data['tipo'] === 'alimentos' ? DispatchArea::Kitchen : DispatchArea::Bar,
        ]));

        return back()->with('status', 'Categoría creada en el menú.');
    }

    public function storeProduct(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer'],
            'kind' => ['required', 'in:simple,receta'],
            'inventory_item_id' => ['nullable', 'integer'],
        ]);

        app(VendorContext::class)->runAs($record, function () use ($data): void {
            // Con el comercio activo, una categoría o insumo ajenos no existen.
            $category = Category::query()->findOrFail((int) $data['category_id']);

            $esReceta = $data['kind'] === 'receta';
            $itemId = null;

            if (! $esReceta && filled($data['inventory_item_id'] ?? null)) {
                $itemId = InventoryItem::query()
                    ->findOrFail((int) $data['inventory_item_id'])->id;
            }

            Product::create([
                'category_id' => $category->id,
                'name' => $data['name'],
                'type' => $esReceta ? ProductType::Recipe : ProductType::Simple,
                'price_cents' => (int) round(((float) $data['price']) * 100),
                'track_stock' => $itemId !== null,
                'inventory_item_id' => $itemId,
            ]);
        });

        return back()->with('status', 'Producto añadido al menú.');
    }

    public function storeRecipeItem(Request $request, int $vendor, int $product): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        app(VendorContext::class)->runAs($record, function () use ($product, $data): void {
            $target = Product::query()->findOrFail($product);

            // Los guards del dominio rematan: producto tipo receta, insumo
            // del MISMO comercio, cantidades positivas.
            $target->recipeItems()->create([
                'inventory_item_id' => (int) $data['inventory_item_id'],
                'quantity' => round((float) $data['quantity'], 3),
            ]);
        });

        return back()->with('status', 'Ingrediente añadido a la receta.');
    }

    public function destroyRecipeItem(Request $request, int $vendor, int $product, int $item): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        app(VendorContext::class)->runAs($record, function () use ($product, $item): void {
            Product::query()->findOrFail($product)
                ->recipeItems()->findOrFail($item)
                ->delete();
        });

        return back()->with('status', 'Ingrediente retirado de la receta.');
    }

    public function updateProduct(Request $request, int $vendor, int $product): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        app(VendorContext::class)->runAs($record, function () use ($product, $data): void {
            // El scope del comercio activo hace el 404 de lo ajeno.
            $target = Product::query()->findOrFail($product);

            $target->update(array_filter([
                'price_cents' => isset($data['price']) ? (int) round(((float) $data['price']) * 100) : null,
                'active' => isset($data['active']) ? (bool) $data['active'] : null,
            ], fn ($value) => $value !== null));
        });

        return back()->with('status', 'Producto actualizado.');
    }
}
