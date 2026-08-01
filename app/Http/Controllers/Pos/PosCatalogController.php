<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * El catálogo vendible para cachear offline. Los scopes hacen el trabajo:
 * el personal de un comercio recibe SOLO su catálogo; una cuenta de negocio
 * recibe el suyo completo.
 */
class PosCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'dispatch']),
            'products' => Product::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'category_id', 'name', 'type', 'price_cents', 'itbis_exempt']),
        ]);
    }
}
