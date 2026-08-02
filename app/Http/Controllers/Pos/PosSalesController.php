<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Actions\RefundOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Refund;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las ventas del turno vistas desde el POS, y la devolución de dinero.
 *
 * El listado es de la CAJA, no del comercio entero: es lo que el cajero
 * necesita para buscar «la venta de hace un rato». Los scopes hacen el
 * resto — lo de otro comercio no existe.
 */
class PosSalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cash_session_id' => ['required', 'integer'],
        ]);

        // El scope de comercio ya filtra: una caja ajena no se encuentra.
        $session = CashSession::query()->findOrFail((int) $data['cash_session_id']);

        $orders = Order::query()
            ->where('cash_session_id', $session->id)
            ->with(['payments', 'refunds'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id,
                'number' => $order->publicNumber(),
                'status' => $order->status->value,
                'total_cents' => $order->total_cents,
                'refunded_cents' => (int) $order->refunds->sum('amount_cents'),
                'method' => $order->payments->first()?->method->value,
                'paid_at' => $order->paid_at,
            ])->values(),
        ]);
    }

    public function refund(Request $request, int $order): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permission::SalesRefund->value), 403);

        $data = $request->validate([
            'cash_session_id' => ['required', 'integer'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $refund = app(RefundOrder::class)(
            Order::query()->findOrFail($order),
            CashSession::query()->findOrFail((int) $data['cash_session_id']),
            (int) $data['amount_cents'],
            $data['reason'],
            filled($data['method'] ?? null) ? PaymentMethod::from($data['method']) : null,
            $request->user(),
        );

        $devuelto = (int) Refund::query()->where('order_id', $refund->order_id)->sum('amount_cents');

        return response()->json([
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'amount_cents' => $refund->amount_cents,
            'refunded_cents' => $devuelto,
            'method' => $refund->method->value,
            'reason' => $refund->reason,
        ], 201);
    }
}
