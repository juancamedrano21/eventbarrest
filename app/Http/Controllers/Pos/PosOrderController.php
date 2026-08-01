<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El endpoint de sincronización del POS offline: recibe una venta terminada
 * (líneas + cobro) y la registra IDEMPOTENTEMENTE — reenviar la misma
 * client_ref mil veces produce una sola orden, un solo cobro y un solo
 * descuento de stock. La respuesta es el estado real en el servidor.
 */
class PosOrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::SalesOperate->value) === true, 403);

        $data = $request->validate([
            'cash_session_id' => ['required', 'integer'],
            'client_ref' => ['required', 'string', 'max:40'],
            'with_tip' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
            'payment.method' => ['required', 'in:cash,card,transfer'],
            'payment.tendered_cents' => ['required', 'integer', 'min:0'],
        ]);

        $session = CashSession::query()->findOrFail($data['cash_session_id']);

        $order = app(PlaceOrder::class)(
            $session,
            $data['lines'],
            $data['client_ref'],
            $request->user(),
            (bool) ($data['with_tip'] ?? false),
        );

        if ($order->status === OrderStatus::Open) {
            try {
                $order = app(PayOrder::class)(
                    $order,
                    PaymentMethod::from($data['payment']['method']),
                    (int) $data['payment']['tendered_cents'],
                );
            } catch (SalesException $exception) {
                // Carrera del reenvío: si otro request la cobró primero, la
                // respuesta correcta es el estado real, no un error.
                $order = Order::query()->findOrFail($order->id);

                if ($order->status !== OrderStatus::Paid) {
                    throw $exception;
                }
            }
        }

        return response()->json([
            'id' => $order->id,
            'client_ref' => $order->client_ref,
            'status' => $order->status->value,
            'subtotal_cents' => $order->subtotal_cents,
            'itbis_cents' => $order->itbis_cents,
            'tip_cents' => $order->tip_cents,
            'total_cents' => $order->total_cents,
            'paid_at' => $order->paid_at,
        ]);
    }
}
