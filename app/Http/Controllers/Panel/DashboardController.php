<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El dashboard con los números REALES de la cuenta: la fase «sustituir» de
 * la plantilla. Ventas del día y del período, cajas abiertas, la serie
 * diaria, el desglose por comercio y — el reporte del organizador — la
 * COMISIÓN por evento calculada de las ventas cobradas.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // El personal de comercio no vive en este panel todavía.
        if ($user instanceof User && $user->worksForAVendor()) {
            return redirect('/app');
        }

        $paid = fn () => Order::query()->where('orders.status', OrderStatus::Paid->value);

        $ventasPorDia = $paid()
            ->whereDate('paid_at', '>=', today()->subDays(13))
            ->selectRaw('DATE(paid_at) as dia, SUM(total_cents) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total', 'dia');

        $serie = collect(range(13, 0))->map(fn (int $atras) => [
            'dia' => today()->subDays($atras)->format('d M'),
            'total' => round(((int) ($ventasPorDia[today()->subDays($atras)->toDateString()] ?? 0)) / 100, 2),
        ]);

        $esOrganizador = $user?->tenant instanceof OrganizerAccount;

        return view('panel.dashboard', [
            'salesToday' => (int) $paid()->whereDate('paid_at', today())->sum('total_cents'),
            'sales30' => (int) $paid()->whereDate('paid_at', '>=', today()->subDays(29))->sum('total_cents'),
            'openSessions' => CashSession::query()->where('status', CashSessionStatus::Open->value)->count(),
            'vendorsCount' => $esOrganizador ? Vendor::query()->count() : null,
            'serie' => $serie,
            'porComercio' => $esOrganizador
                ? $paid()
                    ->join('vendors as v', 'v.id', '=', 'orders.vendor_id')
                    ->selectRaw('v.name as nombre, COUNT(*) as ordenes, SUM(orders.total_cents) as total')
                    ->groupBy('v.id', 'v.name')
                    ->orderByDesc('total')
                    ->get()
                : collect(),
            'porEvento' => $esOrganizador
                ? $paid()
                    ->join('operating_units as u', 'u.id', '=', 'orders.operating_unit_id')
                    ->join('events as e', 'e.id', '=', 'u.event_id')
                    ->join('event_vendor as ev', function ($join): void {
                        $join->on('ev.event_id', '=', 'u.event_id')
                            ->on('ev.vendor_id', '=', 'orders.vendor_id');
                    })
                    ->selectRaw('e.name as nombre, SUM(orders.total_cents) as bruto, SUM(orders.total_cents * ev.commission_bps / 10000) as comision')
                    ->groupBy('e.id', 'e.name')
                    ->orderByDesc('bruto')
                    ->get()
                : collect(),
            'esOrganizador' => $esOrganizador,
        ]);
    }
}
