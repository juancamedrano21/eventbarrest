<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business\Concerns;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\InventoryItem;
use App\Http\Controllers\Concerns\HandlesProductImage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El menú del negocio. Gemelo de HandlesVendorCatalog, no su reutilización:
 * allí cada operación entra en el comercio con `runAs`, y aquí no hay
 * comercio en el que entrar — el catálogo es de la cuenta entera y su
 * `vendor_id` es nulo. Duplicar el gemelo es lo que evita que estas dos
 * lecturas del mundo acaben decidiéndose con un `if` en código compartido.
 *
 * La unicidad se comprueba con `vendor_id` nulo, que no es un caso
 * particular sino la afirmación de este mundo. En la base la sostiene el
 * índice (tenant_id, vendor_key, name), con vendor_key = COALESCE(vendor_id, 0):
 * sin esa columna generada, en MySQL dos NULL no colisionan y el único no
 * restringiría nada aquí.
 */
trait HandlesBusinessCatalog
{
    use HandlesProductImage;

    protected function createCategory(Request $request, int $tenantId): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')],
            'tipo' => ['required', 'in:alimentos,bebidas'],
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre.',
        ], ['name' => 'nombre']);

        Category::create([
            'name' => $data['name'],
            // La clasificación del menú ES el despacho: Alimentos salen de
            // cocina, Bebidas de barra — el POS y las comandas ya lo usan.
            'dispatch' => $data['tipo'] === 'alimentos' ? DispatchArea::Kitchen : DispatchArea::Bar,
        ]);
    }

    /**
     * Renombrar una categoría o corregir por dónde despacha. Hoy esto solo
     * se puede hacer en el Filament que se va a retirar.
     */
    protected function changeCategory(Request $request, int $tenantId, Category $category): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')
                    ->ignore($category->id)],
            'tipo' => ['required', 'in:alimentos,bebidas'],
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre.',
        ], ['name' => 'nombre']);

        $category->update([
            'name' => $data['name'],
            'dispatch' => $data['tipo'] === 'alimentos' ? DispatchArea::Kitchen : DispatchArea::Bar,
        ]);
    }

    protected function createProduct(Request $request, int $tenantId): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('products', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer'],
            'kind' => ['required', 'in:simple,receta'],
            'inventory_item_id' => ['nullable', 'integer'],
            // Gravado si no se dice lo contrario: el default fiscal seguro.
            'itbis' => ['nullable', 'in:gravado,exento'],
            'image' => $this->reglasDeImagen(),
        ], [
            'name.unique' => 'Ya existe un producto con ese nombre en el menú.',
            'image.max' => 'La foto no puede pesar más de 4 MB.',
        ], ['name' => 'nombre', 'price' => 'precio', 'image' => 'foto']);

        // El scope de la cuenta hace el 404 de lo ajeno.
        $category = Category::query()->findOrFail((int) $data['category_id']);

        $esReceta = $data['kind'] === 'receta';
        $itemId = null;

        if (! $esReceta && filled($data['inventory_item_id'] ?? null)) {
            $itemId = InventoryItem::query()->findOrFail((int) $data['inventory_item_id'])->id;
        }

        Product::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'type' => $esReceta ? ProductType::Recipe : ProductType::Simple,
            'price_cents' => (int) round(((float) $data['price']) * 100),
            'track_stock' => $itemId !== null,
            'inventory_item_id' => $itemId,
            'itbis_exempt' => ($data['itbis'] ?? 'gravado') === 'exento',
            ...$this->imagenDe($request),
        ]);
    }

    protected function changeProduct(Request $request, int $tenantId, Product $product): void
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255',
                Rule::unique('products', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('vendor_id')
                    ->ignore($product->id)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'itbis_exempt' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'inventory_item_id' => ['nullable', 'integer'],
            'image' => $this->reglasDeImagen(),
        ], [
            'name.unique' => 'Ya existe un producto con ese nombre en el menú.',
            'image.max' => 'La foto no puede pesar más de 4 MB.',
        ], ['name' => 'nombre', 'price' => 'precio', 'image' => 'foto']);

        // Solo lo que llega se escribe: el modal de edición no muestra todos
        // los campos, y guardarlos todos borraría en silencio lo que no ve.
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
            $attrs['category_id'] = Category::query()->findOrFail((int) $data['category_id'])->id;
        }

        // El vínculo de inventario es cosa de productos simples (la receta
        // descuenta por escandallo). La clave presente aunque venga vacía
        // significa «desvincular».
        if ($product->type !== ProductType::Recipe && array_key_exists('inventory_item_id', $data)) {
            $itemId = filled($data['inventory_item_id'])
                ? InventoryItem::query()->findOrFail((int) $data['inventory_item_id'])->id
                : null;

            $attrs['inventory_item_id'] = $itemId;
            $attrs['track_stock'] = $itemId !== null;
        }

        $attrs = [...$attrs, ...$this->imagenDe($request, $product)];

        if ($attrs !== []) {
            $product->update($attrs);
        }
    }

    protected function addRecipeItem(Request $request, Product $product): void
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ], [], ['quantity' => 'cantidad']);

        // Se resuelve con el scope para que un insumo ajeno dé 404, como en
        // el resto de la puerta. El guard del dominio queda de red debajo,
        // pero llegar hasta él sería un 500 en vez de un «no existe».
        $item = InventoryItem::query()->findOrFail((int) $data['inventory_item_id']);

        $product->recipeItems()->create([
            'inventory_item_id' => $item->id,
            'quantity' => round((float) $data['quantity'], 3),
        ]);
    }

    protected function removeRecipeItem(Product $product, int $item): void
    {
        $product->recipeItems()->findOrFail($item)->delete();
    }
}
