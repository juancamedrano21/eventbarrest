<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Business\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\OrderLine;
use App\Domains\Sales\Models\Payment;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Domains\Sales\Queries\SalesFigures;
use App\Domains\Sales\Queries\SalesSummary;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El resumen de /business: cómo va el negocio hoy y en el mes.
 *
 * Las cifras vienen de {@see SalesSummary}, que separa la propina legal de
 * lo que es venta del bar. Sumar `total_cents` a secas —lo que hacen los
 * reportes del mundo eventos— le atribuiría al dueño dinero que por ley es
 * del personal, e inflaría de paso cualquier margen contra el costo.
 */
class HomeController extends Controller
{
    use AuthorizesBusinessPanel;

    public function __invoke(Request $request): View
    {
        $negocio = $this->negocioDe($request);
        $user = $request->user();

        $verDinero = (bool) $user?->can(Permission::ReportsViewTenant->value)
            || (bool) $user?->can(Permission::ReportsViewUnit->value);

        $tz = (string) config('app.business_timezone');
        $inicioHoy = (string) today($tz)->utc();
        $inicio30 = (string) today($tz)->subDays(29)->utc();
        $inicioSerie = (string) today($tz)->subDays(13)->utc();

        $resumen = app(SalesSummary::class);
        $mes = $verDinero ? $resumen->forRange($inicio30) : SalesFigures::empty();

        // Una sola consulta para los catorce días; el mapa de abajo solo la
        // lee. Pedirla dentro del bucle serían catorce viajes a la base.
        $porDia = $verDinero ? $resumen->byDay($inicioSerie) : collect();

        $ordenes30 = fn () => Order::query()
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $inicio30);

        return view('business.home', [
            'negocio' => $negocio,
            'verDinero' => $verDinero,
            'modoVigente' => app(ResolveItbisMode::class)->forVendor(null, (int) $negocio->id),

            'hoy' => $verDinero ? $resumen->forRange($inicioHoy) : SalesFigures::empty(),
            'mes' => $mes,
            'ticketPromedio' => $mes->transacciones > 0
                ? (int) round($mes->ventas / $mes->transacciones)
                : 0,
            'itbis30' => $verDinero ? (int) $ordenes30()->sum('itbis_cents') : 0,

            'serie' => collect(range(13, 0))->map(function (int $atras) use ($tz, $porDia): array {
                $dia = today($tz)->subDays($atras);

                return [
                    'dia' => $dia->format('d M'),
                    'total' => round(($porDia[$dia->toDateString()]->ventas ?? 0) / 100, 2),
                ];
            }),

            'porSucursal' => $verDinero ? $resumen->byUnit($inicio30) : collect(),

            'topProductos' => $verDinero
                ? OrderLine::query()
                    ->join('orders as o', 'o.id', '=', 'order_lines.order_id')
                    ->where('o.status', OrderStatus::Paid->value)
                    ->where('o.paid_at', '>=', $inicio30)
                    ->selectRaw('order_lines.product_name as nombre, SUM(order_lines.quantity) as unidades, SUM(order_lines.total_cents) as importe')
                    ->groupBy('order_lines.product_name')
                    ->orderByDesc('importe')
                    ->limit(5)
                    ->toBase()
                    ->get()
                : collect(),

            'porMetodo' => $verDinero
                ? Payment::query()
                    ->whereIn('order_id', $ordenes30()->select('orders.id'))
                    ->selectRaw('method, COUNT(*) as veces, SUM(amount_cents) as total')
                    ->groupBy('method')
                    ->toBase()
                    ->get()
                : collect(),

            'cajasAbiertas' => CashSession::query()
                ->where('status', CashSessionStatus::Open->value)
                ->with('operatingUnit')
                ->get(),

            'sucursales' => Branch::query()->orderBy('name')->get(),

            // Lo que hay que reponer antes de que falte en la barra.
            'bajoMinimo' => StockLevel::query()
                ->whereColumn('quantity', '<=', 'alert_threshold')
                ->where('alert_threshold', '>', 0)
                ->with(['operatingUnit', 'inventoryItem'])
                ->orderBy('quantity')
                ->limit(10)
                ->get(),
        ]);
    }
}
