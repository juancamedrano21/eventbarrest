<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventVendor;

use App\Domains\Catalog\Models\Category;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Queries\NetSales;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventVendor\Concerns\AuthorizesEventVendorPanel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La casa del encargado (ADR-007): SU comercio, implícito por su usuario —
 * jamás elegido por URL. Resumen, menú, ventas e inventario con los mismos
 * parciales que el organizador ve en /panel.
 */
class HomeController extends Controller
{
    use AuthorizesEventVendorPanel;

    public function __invoke(Request $request): View
    {
        $record = $this->comercioDe($request);
        $user = $request->user();

        // El dinero es de quien tiene reportes de su unidad — la misma
        // puerta que protege el detalle de la venta. Almacén compra y
        // recibe; no ve ventas.
        $puede = [
            'catalogo' => (bool) $user?->can(Permission::CatalogManage->value),
            'inventario' => (bool) $user?->can(Permission::InventoryManage->value),
            'ventas' => (bool) $user?->can(Permission::ReportsViewUnit->value),
        ];

        $paid = fn () => Order::query()
            ->where('vendor_id', $record->id)
            ->where('status', OrderStatus::Paid->value);

        $inicioHoy = today(config('app.business_timezone'))->utc();

        return view('event-vendor.home', [
            'vendor' => $record,
            'puede' => $puede,
            // Con qué factura SU comercio: manda el copy de los precios y
            // se muestra en el resumen (la fija el organizador).
            'modoVigente' => app(ResolveItbisMode::class)->forVendor($record->id, (int) $record->tenant_id),
            // Netas: lo devuelto no cuenta como venta.
            'salesToday' => $puede['ventas']
                ? (int) $paid()->where('paid_at', '>=', $inicioHoy)->sum('total_cents')
                    - app(NetSales::class)->refundedBetween((string) $inicioHoy, null, $record->id)
                : 0,
            'salesTotal' => $puede['ventas']
                ? (int) $paid()->sum('total_cents')
                    - app(NetSales::class)->refundedBetween(null, null, $record->id)
                : 0,
            'recentOrders' => $puede['ventas']
                ? Order::query()
                    ->where('vendor_id', $record->id)
                    ->with('operatingUnit')
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get()
                : collect(),
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
                'categorias' => route('event-vendor.categories.store'),
                'productos' => route('event-vendor.products.store'),
                'producto' => fn ($product) => route('event-vendor.products.update', $product),
                'receta' => fn ($product) => route('event-vendor.recipe.store', $product),
                'recetaQuitar' => fn ($product, $ingrediente) => route('event-vendor.recipe.destroy', [$product, $ingrediente]),
                'venta' => fn ($order) => route('event-vendor.sales.show', $order),
                'insumos' => route('event-vendor.items.store'),
                'compras' => route('event-vendor.purchases.store'),
            ],
        ]);
    }
}
