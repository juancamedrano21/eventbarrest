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
use Illuminate\Validation\Rule;

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
            // Gravado si no se dice lo contrario: el default fiscal seguro.
            'itbis' => ['nullable', 'in:gravado,exento'],
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
                'itbis_exempt' => ($data['itbis'] ?? 'gravado') === 'exento',
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
            'name' => ['nullable', 'string', 'max:255',
                // Único dentro del comercio, no de la cuenta: dos comercios
                // pueden vender su «Mojito» (misma regla que Filament).
                Rule::unique('products', 'name')
                    ->where('tenant_id', $record->tenant_id)
                    ->where('vendor_id', $record->id)
                    ->ignore($product)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'itbis_exempt' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'inventory_item_id' => ['nullable', 'integer'],
        ], [
            'name.unique' => 'Ya existe un producto con ese nombre en este comercio.',
        ]);

        app(VendorContext::class)->runAs($record, function () use ($product, $data): void {
            // El scope del comercio activo hace el 404 de lo ajeno.
            $target = Product::query()->findOrFail($product);

            $attrs = [];

            if (filled($data['name'] ?? null)) {
                $attrs['name'] = $data['name'];
            }

            if (isset($data['price'])) {
                $attrs['price_cents'] = (int) round(((float) $data['price']) * 100);
            }

            if (isset($data['active'])) {
                $attrs['active'] = (bool) $data['active'];
            }

            if (isset($data['itbis_exempt'])) {
                $attrs['itbis_exempt'] = (bool) $data['itbis_exempt'];
            }

            if (filled($data['category_id'] ?? null)) {
                // Con el comercio activo, una categoría ajena no existe.
                $attrs['category_id'] = Category::query()
                    ->findOrFail((int) $data['category_id'])->id;
            }

            // El vínculo de inventario es cosa de productos simples (la
            // receta descuenta por escandallo). La clave presente aunque
            // venga vacía significa «desvincular».
            if ($target->type !== ProductType::Recipe && array_key_exists('inventory_item_id', $data)) {
                $itemId = filled($data['inventory_item_id'])
                    ? InventoryItem::query()->findOrFail((int) $data['inventory_item_id'])->id
                    : null;

                $attrs['inventory_item_id'] = $itemId;
                $attrs['track_stock'] = $itemId !== null;
            }

            if ($attrs !== []) {
                $target->update($attrs);
            }
        });

        return back()->with('status', 'Producto actualizado.');
    }
}
