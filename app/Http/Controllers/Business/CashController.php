<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Business\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Payment;
use App\Domains\Sales\Models\Refund;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Los arqueos: lo primero que un dueño mira cada mañana. Cuánto había que
 * tener en la gaveta, cuánto se contó y de cuánto es la diferencia.
 *
 * Solo se MIRA desde aquí. Abrir y cerrar una caja ocurre en el POS, junto
 * al dinero: cerrar un turno desde una oficina, sin contar los billetes, no
 * es un arqueo.
 *
 * De la caja abierta se muestra el esperado EN VIVO con la misma cuenta que
 * hará el cierre —fondo + cobros en efectivo − devoluciones en efectivo—,
 * para que nadie tenga que fiarse de una cifra distinta a la del corte.
 */
class CashController extends Controller
{
    use AuthorizesBusinessPanel;

    public function index(Request $request): View
    {
        $this->negocioDe($request, Permission::ReportsViewUnit->value);

        $sucursal = $request->integer('sucursal') ?: null;

        $sesiones = CashSession::query()
            ->when($sucursal !== null, fn ($q) => $q->where('operating_unit_id', $sucursal))
            ->with(['operatingUnit', 'user'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // El efectivo de cada turno, en dos consultas para todas las filas
        // en pantalla: una por caja sería un viaje por fila.
        $ids = $sesiones->getCollection()->pluck('id')->all();

        $cobradoEnEfectivo = Payment::query()
            ->where('payments.method', PaymentMethod::Cash->value)
            ->join('orders as o', 'o.id', '=', 'payments.order_id')
            ->whereIn('o.cash_session_id', $ids)
            ->where('o.status', OrderStatus::Paid->value)
            ->selectRaw('o.cash_session_id as sesion, SUM(payments.amount_cents) as total')
            ->groupBy('o.cash_session_id')
            ->toBase()
            ->pluck('total', 'sesion');

        $devueltoEnEfectivo = Refund::query()
            ->whereIn('cash_session_id', $ids)
            ->where('method', PaymentMethod::Cash->value)
            ->selectRaw('cash_session_id as sesion, SUM(amount_cents) as total')
            ->groupBy('cash_session_id')
            ->toBase()
            ->pluck('total', 'sesion');

        // La propina en efectivo del turno: lo que hay en la gaveta para
        // repartir, y que no es del negocio.
        $propinaEnEfectivo = CashSession::query()
            ->whereIn('cash_sessions.id', $ids)
            ->join('orders as o', 'o.cash_session_id', '=', 'cash_sessions.id')
            ->where('o.status', OrderStatus::Paid->value)
            ->whereExists(fn ($q) => $q->from('payments')
                ->whereColumn('payments.order_id', 'o.id')
                ->where('payments.method', PaymentMethod::Cash->value))
            ->selectRaw('cash_sessions.id as sesion, SUM(o.tip_cents) as total')
            ->groupBy('cash_sessions.id')
            ->toBase()
            ->pluck('total', 'sesion');

        $sesiones->getCollection()->transform(function (CashSession $sesion) use ($cobradoEnEfectivo, $devueltoEnEfectivo, $propinaEnEfectivo): CashSession {
            $cobrado = (int) ($cobradoEnEfectivo[$sesion->id] ?? 0);
            $devuelto = (int) ($devueltoEnEfectivo[$sesion->id] ?? 0);

            $sesion->setAttribute('efectivo_cobrado', $cobrado);
            $sesion->setAttribute('efectivo_devuelto', $devuelto);
            $sesion->setAttribute('propina_efectivo', (int) ($propinaEnEfectivo[$sesion->id] ?? 0));
            // En una caja cerrada manda lo que se guardó al cerrar; en una
            // abierta, la misma cuenta calculada ahora.
            $sesion->setAttribute(
                'esperado_vivo',
                $sesion->isOpen()
                    ? $sesion->opening_cents + $cobrado - $devuelto
                    : (int) $sesion->expected_cents,
            );

            return $sesion;
        });

        return view('business.cash', [
            'sesiones' => $sesiones,
            'sucursales' => Branch::query()->orderBy('name')->get(),
            'sucursalFiltrada' => $sucursal,
            'abiertas' => CashSession::query()
                ->where('status', CashSessionStatus::Open->value)
                ->count(),
            'tz' => (string) config('app.business_timezone'),
        ]);
    }
}
