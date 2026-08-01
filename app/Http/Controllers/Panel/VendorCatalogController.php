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

    public function storeProduct(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::CatalogManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer'],
            'new_category' => ['nullable', 'string', 'max:255', 'required_without:category_id'],
        ]);

        app(VendorContext::class)->runAs($record, function () use ($data): void {
            $categoryId = $data['category_id'] ?? null;

            if ($categoryId !== null) {
                // Con el comercio activo, una categoría ajena no existe.
                $categoryId = Category::query()->findOrFail((int) $categoryId)->id;
            } else {
                $categoryId = Category::query()->firstOrCreate(
                    ['name' => trim((string) $data['new_category'])],
                    ['dispatch' => DispatchArea::Bar],
                )->id;
            }

            Product::create([
                'category_id' => $categoryId,
                'name' => $data['name'],
                'type' => ProductType::Simple,
                'price_cents' => (int) round(((float) $data['price']) * 100),
            ]);
        });

        return back()->with('status', 'Producto creado en el catálogo del comercio.');
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
