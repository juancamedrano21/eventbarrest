<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Business\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Queries\SalesSummary;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Las ventas del negocio: el listado y el detalle inmutable de cada una.
 *
 * Una venta cobrada no se edita ni se borra por ninguna vía. Corregirla es
 * otro asiento —un reembolso— y eso ocurre en el POS, donde está la gaveta
 * de la que sale el dinero.
 */
class SalesController extends Controller
{
    use AuthorizesBusinessPanel;

    public function index(Request $request): View
    {
        $this->negocioDe($request, Permission::ReportsViewUnit->value);

        $sucursal = $request->integer('sucursal') ?: null;
        $desde = $request->date('desde');
        $hasta = $request->date('hasta');

        $tz = (string) config('app.business_timezone');
        $desdeUtc = $desde !== null ? (string) $desde->startOfDay()->setTimezone('UTC') : null;
        // Rango cerrado por la izquierda y abierto por la derecha: «hasta el
        // 5» incluye el día 5 entero.
        $hastaUtc = $hasta !== null ? (string) $hasta->addDay()->startOfDay()->setTimezone('UTC') : null;

        $ordenes = Order::query()
            ->when($sucursal !== null, fn ($q) => $q->where('operating_unit_id', $sucursal))
            ->when($desdeUtc !== null, fn ($q) => $q->where('paid_at', '>=', $desdeUtc))
            ->when($hastaUtc !== null, fn ($q) => $q->where('paid_at', '<', $hastaUtc))
            ->where('status', OrderStatus::Paid->value)
            ->with(['operatingUnit', 'user'])
            ->latest('paid_at')
            ->paginate(30)
            ->withQueryString();

        return view('business.sales', [
            'ordenes' => $ordenes,
            'resumen' => app(SalesSummary::class)->forRange($desdeUtc, $hastaUtc, $sucursal),
            'sucursales' => Branch::query()->orderBy('name')->get(),
            'sucursalFiltrada' => $sucursal,
            'desde' => $desde?->toDateString(),
            'hasta' => $hasta?->toDateString(),
            'tz' => $tz,
        ]);
    }

    public function show(Request $request, int $order): View
    {
        $negocio = $this->negocioDe($request, Permission::ReportsViewUnit->value);

        $order = Order::query()
            ->with(['lines', 'payments', 'refunds.user', 'operatingUnit', 'cashSession', 'user'])
            ->findOrFail($order);

        return view('event-panel.vendors.sale', [
            'titular' => $negocio,
            'sale' => $order,
            'payment' => $order->payments->first(),
            'tz' => (string) config('app.business_timezone'),
            'volver' => route('business.sales.index'),
            // El chrome de SU puerta.
            'layoutVenta' => 'business.layout',
        ]);
    }
}
