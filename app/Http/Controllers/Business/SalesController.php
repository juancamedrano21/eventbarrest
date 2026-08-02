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
use Illuminate\Support\Carbon;
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

        // Sin validar, una fecha a mano en la URL revienta en Carbon::parse.
        $filtros = $request->validate([
            'sucursal' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ], [], ['desde' => 'fecha desde', 'hasta' => 'fecha hasta']);

        $sucursal = (int) ($filtros['sucursal'] ?? 0) ?: null;
        $tz = (string) config('app.business_timezone');

        // El día del negocio empieza a medianoche EN RD, no en UTC. Sin esto
        // la franja de más venta de un bar —de las ocho a las doce— se le
        // atribuiría al día siguiente, y el resumen de portada, que sí corta
        // en hora local, contradiría a este listado sobre el mismo día.
        $desde = filled($filtros['desde'] ?? null)
            ? Carbon::parse($filtros['desde'], $tz)->startOfDay()
            : null;
        $hasta = filled($filtros['hasta'] ?? null)
            ? Carbon::parse($filtros['hasta'], $tz)->startOfDay()
            : null;

        $desdeUtc = $desde?->copy()->utc()->toDateTimeString();
        // Rango cerrado por la izquierda y abierto por la derecha: «hasta el
        // 5» incluye el día 5 entero. Sobre una COPIA: Carbon es mutable, y
        // mutarlo aquí repintaría el formulario con un día de más — y lo
        // correría otro día en cada reenvío.
        $hastaUtc = $hasta?->copy()->addDay()->utc()->toDateTimeString();

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
