<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Enums\SalesChannel;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment.method' => ['required', 'in:cash,card,transfer'],
            'payment.tendered_cents' => ['required', 'integer', 'min:0'],
            // La hora del dispositivo. Opcional a propósito: un borrador
            // guardado antes de que existiera este campo sigue sincronizando.
            'sold_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $session = CashSession::query()->findOrFail($data['cash_session_id']);

        // Alta y cobro en UNA transacción: si el cobro falla, la orden no
        // queda Open huérfana bloqueando el cierre de caja — el reintento
        // parte de cero (el lookup idempotente tolera el rollback).
        $created = false;

        // attempts=3: es la transacción EXTERIOR, y por tanto la única capa
        // donde Laravel reintenta de verdad — dentro son savepoints y el
        // error de concurrencia se relanza sin reintentar.
        $order = DB::transaction(function () use ($session, $data, $request, &$created): Order {
            $order = app(PlaceOrder::class)(
                $session,
                $data['lines'],
                $data['client_ref'],
                $request->user(),
                (bool) ($data['with_tip'] ?? false),
                SalesChannel::Pos,
                $data['customer_name'] ?? null,
                filled($data['sold_at'] ?? null) ? CarbonImmutable::parse((string) $data['sold_at']) : null,
            );

            // La señal de alta se captura AQUÍ: el cobro relee con lock y
            // devuelve otra instancia hidratada de la base.
            $created = $order->wasRecentlyCreated;

            if ($order->status === OrderStatus::Open) {
                try {
                    $order = app(PayOrder::class)(
                        $order,
                        PaymentMethod::from($data['payment']['method']),
                        (int) $data['payment']['tendered_cents'],
                    );
                } catch (SalesException $exception) {
                    // Carrera del reenvío: si otro request la cobró primero,
                    // la respuesta correcta es el estado real, no un error.
                    $order = Order::query()->findOrFail($order->id);

                    if ($order->status !== OrderStatus::Paid) {
                        throw $exception;
                    }
                }
            }

            return $order;
        }, 3);

        // Una venta anulada no se «re-cobra» con la misma referencia: el
        // POS debe renumerar y reenviar. Contrato explícito, no un 200 mudo.
        if ($order->status === OrderStatus::Void) {
            return response()->json([
                'code' => 'order_voided',
                'message' => 'Esa referencia corresponde a una orden anulada: renumera y reenvía.',
                'id' => $order->id,
                'number' => $order->publicNumber(),
                'client_ref' => $order->client_ref,
                'voided_at' => $order->voided_at,
                'void_reason' => $order->void_reason,
            ], 409);
        }

        $order->load(['lines', 'payments']);
        $payment = $order->payments->first();

        return response()->json([
            'id' => $order->id,
            'number' => $order->publicNumber(),
            'client_ref' => $order->client_ref,
            'customer_name' => $order->customer_name,
            'cash_session_id' => $order->cash_session_id,
            'status' => $order->status->value,
            'subtotal_cents' => $order->subtotal_cents,
            'itbis_cents' => $order->itbis_cents,
            'tip_cents' => $order->tip_cents,
            'total_cents' => $order->total_cents,
            // La modalidad con la que el servidor calculó: el dispositivo
            // compara y manda a revision si no coincide con lo que cobro.
            'itbis_mode' => $order->itbis_mode->value,
            'paid_at' => $order->paid_at,
            'lines' => $order->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name,
                'notes' => $line->notes,
                'quantity' => (float) $line->quantity,
                'unit_price_cents' => $line->unit_price_cents,
                'total_cents' => $line->total_cents,
            ]),
            'payment' => $payment === null ? null : [
                'method' => $payment->method->value,
                'amount_cents' => $payment->amount_cents,
                'tendered_cents' => $payment->tendered_cents,
                'change_cents' => $payment->change_cents,
            ],
        ], $created ? 201 : 200);
    }
}
