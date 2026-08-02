<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las operaciones del catálogo del comercio, UNA sola vez para las dos
 * puertas (/panel del organizador y /comercio del encargado): quien llama
 * ya autorizó y resolvió el comercio; aquí se valida y se ejecuta COMO el
 * comercio (runAs), con los guards de dominio rematando.
 */
trait HandlesVendorCatalog
{
    use HandlesProductImage;

    protected function createCategory(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('categories', 'name')
                    ->where('tenant_id', $record->tenant_id)
                    ->where('vendor_id', $record->id)],
            'tipo' => ['required', 'in:alimentos,bebidas'],
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre en este comercio.',
        ], ['name' => 'nombre']);

        app(VendorContext::class)->runAs($record, fn () => Category::create([
            'name' => $data['name'],
            // La clasificación del menú ES el despacho: Alimentos salen de
            // cocina, Bebidas de barra — el POS y las comandas ya lo usan.
            'dispatch' => $data['tipo'] === 'alimentos' ? DispatchArea::Kitchen : DispatchArea::Bar,
        ]));
    }

    protected function createProduct(Request $request, Vendor $record): void
    {
        $data = $request->validate([
            // Único dentro del comercio, como en la edición: sin esta regla
            // el índice de la base lo rechazaba con un 500 en la cara.
            'name' => ['required', 'string', 'max:255',
                Rule::unique('products', 'name')
                    ->where('tenant_id', $record->tenant_id)
                    ->where('vendor_id', $record->id)],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer'],
            'kind' => ['required', 'in:simple,receta'],
            'inventory_item_id' => ['nullable', 'integer'],
            // Gravado si no se dice lo contrario: el default fiscal seguro.
            'itbis' => ['nullable', 'in:gravado,exento'],
            'image' => $this->reglasDeImagen(),
        ], [
            'name.unique' => 'Ya existe un producto con ese nombre en este comercio.',
            'image.max' => 'La foto no puede pesar más de 4 MB.',
        ], ['name' => 'nombre', 'price' => 'precio', 'image' => 'foto']);

        // FUERA del runAs: guardar un archivo no necesita contexto y así el
        // disco no queda a medias si el guard del dominio rechaza el producto.
        $imagen = $this->imagenDe($request);

        app(VendorContext::class)->runAs($record, function () use ($data, $imagen): void {
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
                ...$imagen,
            ]);
        });
    }

    protected function changeProduct(Request $request, Vendor $record, int $product): void
    {
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
            'image' => $this->reglasDeImagen(),
        ], [
            'name.unique' => 'Ya existe un producto con ese nombre en este comercio.',
            'image.max' => 'La foto no puede pesar más de 4 MB.',
        ], ['image' => 'foto']);

        app(VendorContext::class)->runAs($record, function () use ($request, $product, $data): void {
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

            $attrs = [...$attrs, ...$this->imagenDe($request, $target)];

            if ($attrs !== []) {
                $target->update($attrs);
            }
        });
    }

    protected function addRecipeItem(Request $request, Vendor $record, int $product): void
    {
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
    }

    protected function removeRecipeItem(Vendor $record, int $product, int $item): void
    {
        app(VendorContext::class)->runAs($record, function () use ($product, $item): void {
            Product::query()->findOrFail($product)
                ->recipeItems()->findOrFail($item)
                ->delete();
        });
    }
}
