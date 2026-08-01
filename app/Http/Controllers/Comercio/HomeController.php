<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comercio;

use App\Domains\Catalog\Models\Category;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Comercio\Concerns\AuthorizesComercioPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La casa del encargado (ADR-007): SU comercio, implícito por su usuario —
 * jamás elegido por URL. Resumen, menú, ventas e inventario con los mismos
 * parciales que el organizador ve en /panel.
 */
class HomeController extends Controller
{
    use AuthorizesComercioPanel;

    public function __invoke(Request $request): View
    {
        $record = $this->comercioDe($request);

        $paid = fn () => Order::query()
            ->where('vendor_id', $record->id)
            ->where('status', OrderStatus::Paid->value);

        $inicioHoy = today(config('app.business_timezone'))->utc();

        return view('comercio.home', [
            'vendor' => $record,
            'salesToday' => (int) $paid()->where('paid_at', '>=', $inicioHoy)->sum('total_cents'),
            'salesTotal' => (int) $paid()->sum('total_cents'),
            'recentOrders' => Order::query()
                ->where('vendor_id', $record->id)
                ->with('operatingUnit')
                ->orderByDesc('id')
                ->limit(15)
                ->get(),
            'stockLevels' => StockLevel::query()
                ->whereHas('operatingUnit', fn ($q) => $q->where('vendor_id', $record->id))
                ->with(['operatingUnit', 'inventoryItem'])
                ->orderBy('inventory_item_id')
                ->get(),
            'menuCategories' => app(VendorContext::class)->runAs(
                $record,
                fn () => Category::query()
                    ->with([
                        'products' => fn ($q) => $q->orderBy('name'),
                        'products.inventoryItem',
                        'products.recipeItems.inventoryItem',
                    ])
                    ->orderBy('name')
                    ->get(),
            ),
            'vendorItems' => app(VendorContext::class)->runAs(
                $record,
                fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'),
            ),
            'outlets' => EventOutlet::query()
                ->where('vendor_id', $record->id)
                ->with('event')
                ->orderBy('name')
                ->get(),
            'urls' => [
                'categorias' => route('comercio.categories.store'),
                'productos' => route('comercio.products.store'),
                'producto' => fn ($product) => route('comercio.products.update', $product),
                'receta' => fn ($product) => route('comercio.recipe.store', $product),
                'recetaQuitar' => fn ($product, $ingrediente) => route('comercio.recipe.destroy', [$product, $ingrediente]),
                'venta' => fn ($order) => route('comercio.sales.show', $order),
                'insumos' => route('comercio.items.store'),
                'compras' => route('comercio.purchases.store'),
            ],
        ]);
    }
}
