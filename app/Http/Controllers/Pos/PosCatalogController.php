<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El catálogo vendible para cachear offline. Los scopes hacen el trabajo:
 * el personal de un comercio recibe SOLO su catálogo; una cuenta de negocio
 * recibe el suyo completo. Viaja también la regla fiscal vigente, para que
 * el ticket del dispositivo cuadre con lo que calculará el servidor.
 */
class PosCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'settings' => [
                'itbis_mode' => app(ResolveItbisMode::class)->forVendor(
                    $user?->vendor_id,
                    (int) app(TenantContext::class)->id(),
                )->value,
            ],
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'dispatch']),
            // Se mandan TODOS, activos o no: el POS pinta los inactivos en
            // gris y sin poder tocarlos, que dice más que esconderlos —el
            // cajero sabe que el plato existe y hoy no se sirve.
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'category_id', 'name', 'image_path', 'type', 'price_cents', 'itbis_exempt', 'active'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'price_cents' => $product->price_cents,
                    'itbis_exempt' => $product->itbis_exempt,
                    'active' => $product->active,
                    'image_url' => $product->imageUrl(),
                ]),
        ]);
    }
}
