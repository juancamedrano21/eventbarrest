<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use stdClass;

/**
 * El dashboard con los números REALES de la cuenta: ventas del día y del
 * período, cajas abiertas, la serie diaria, el desglose por comercio y — el
 * reporte del organizador — la COMISIÓN por evento, sumada de la comisión
 * congelada en cada orden al vender.
 *
 * Los días se cortan en la zona del negocio (RD, UTC-4 fijo): una venta de
 * las 9 de la noche es del día que el bar vivió, no del que dice UTC.
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

        $esOrganizador = $user?->tenant instanceof OrganizerAccount;

        // El consolidado del dinero es de quien tiene reportes de la
        // CUENTA; a los demás el panel les abre sin números. Fail-closed.
        if (! ($user instanceof User && $user->can(Permission::ReportsViewTenant->value))) {
            return view('panel.dashboard', [
                'conReportes' => false,
                'esOrganizador' => $esOrganizador,
                'salesToday' => 0,
                'sales30' => 0,
                'openSessions' => 0,
                'vendorsCount' => null,
                'serie' => collect(),
                'porComercio' => collect(),
                'porEvento' => collect(),
            ]);
        }

        $tz = (string) config('app.business_timezone');
        $inicioHoy = today($tz)->utc();
        $inicio30 = today($tz)->subDays(29)->utc();
        $inicioSerie = today($tz)->subDays(13)->utc();

        // Agrupar por día LOCAL: paid_at vive en UTC y aquí se corre el
        // offset (fijo en RD, sin horario de verano) antes de tomar la fecha.
        $diaLocal = DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("DATE(paid_at, '%+d minutes')", now($tz)->utcOffset())
            : sprintf("DATE(CONVERT_TZ(paid_at, '+00:00', '%s'))", now($tz)->format('P'));

        $paid = fn () => Order::query()->where('orders.status', OrderStatus::Paid->value);

        $ventasPorDia = $paid()
            ->where('paid_at', '>=', $inicioSerie)
            ->selectRaw("{$diaLocal} as dia, SUM(total_cents) as total")
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total', 'dia');

        $serie = collect(range(13, 0))->map(fn (int $atras) => [
            'dia' => today($tz)->subDays($atras)->format('d M'),
            'total' => round(((int) ($ventasPorDia[today($tz)->subDays($atras)->toDateString()] ?? 0)) / 100, 2),
        ]);

        return view('panel.dashboard', [
            'conReportes' => true,
            'esOrganizador' => $esOrganizador,
            'salesToday' => (int) $paid()->where('paid_at', '>=', $inicioHoy)->sum('total_cents'),
            'sales30' => (int) $paid()->where('paid_at', '>=', $inicio30)->sum('total_cents'),
            'openSessions' => CashSession::query()->where('status', CashSessionStatus::Open->value)->count(),
            'vendorsCount' => $esOrganizador ? Vendor::query()->count() : null,
            'serie' => $serie,
            'porComercio' => $esOrganizador
                ? $paid()
                    ->where('paid_at', '>=', $inicio30)
                    ->join('vendors as v', 'v.id', '=', 'orders.vendor_id')
                    ->selectRaw('v.name as nombre, COUNT(*) as ordenes, SUM(orders.total_cents) as total')
                    ->groupBy('v.id', 'v.name')
                    ->orderByDesc('total')
                    ->get()
                : collect(),
            // Desde la comisión CONGELADA en cada orden: renegociar o quitar
            // la participación no reescribe lo cobrado. La suma es entera y
            // exacta en cualquier motor; se divide y redondea UNA vez aquí.
            'porEvento' => $esOrganizador
                ? $paid()
                    ->join('operating_units as u', 'u.id', '=', 'orders.operating_unit_id')
                    ->join('events as e', 'e.id', '=', 'u.event_id')
                    ->selectRaw('e.name as nombre, SUM(orders.total_cents) as bruto, SUM(orders.total_cents * COALESCE(orders.commission_bps, 0)) as comision_bps')
                    ->groupBy('e.id', 'e.name')
                    ->orderByDesc('bruto')
                    ->toBase()
                    ->get()
                    ->map(fn (stdClass $fila): object => (object) [
                        'nombre' => (string) $fila->nombre,
                        'bruto' => (int) $fila->bruto,
                        'comision' => (int) round(((int) $fila->comision_bps) / 10000),
                    ])
                : collect(),
        ]);
    }
}
