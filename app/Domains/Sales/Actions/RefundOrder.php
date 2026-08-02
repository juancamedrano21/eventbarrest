<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve dinero de una venta cobrada, total o parcialmente.
 *
 * La venta NO se edita: sigue siendo el registro de lo que ocurrió. El
 * reembolso es un hecho nuevo que la referencia — así lo pide la
 * contabilidad y así lo exigirá la DGII, donde esto será una nota de
 * crédito.
 *
 * El dinero sale de la caja ABIERTA de quien devuelve (no de la de la
 * venta original, que suele estar cerrada): es ese arqueo el que tiene que
 * cuadrar al final del turno.
 *
 * El inventario NO se repone: lo que salió de la barra ya se sirvió. Si
 * algún día hay devoluciones que sí vuelven al stock, será un ajuste
 * explícito, no un efecto secundario del dinero.
 */
class RefundOrder
{
    public function __invoke(
        Order $order,
        CashSession $session,
        int $amountCents,
        string $reason,
        ?PaymentMethod $method = null,
        ?User $user = null,
    ): Refund {
        if (trim($reason) === '') {
            throw SalesException::refundNeedsAReason();
        }

        if ($amountCents <= 0) {
            throw SalesException::refundAmountInvalid();
        }

        $orderId = $order->id;
        $sessionId = $session->id;

        return DB::transaction(function () use ($orderId, $sessionId, $amountCents, $reason, $method, $user): Refund {
            // Con lock y con scopes: la venta de otro comercio no existe, y
            // el monto disponible se decide sobre el estado comiteado.
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first()
                ?? throw SalesException::orderNotFoundForRefund();

            if ($order->status !== OrderStatus::Paid) {
                throw SalesException::onlyPaidOrdersAreRefundable();
            }

            $yaDevuelto = (int) Refund::query()->where('order_id', $order->id)->sum('amount_cents');
            $disponible = $order->total_cents - $yaDevuelto;

            if ($amountCents > $disponible) {
                throw SalesException::refundAboveRemaining($disponible);
            }

            // La gaveta de la que sale el dinero tiene que estar abierta.
            $session = CashSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            if ($session === null || ! $session->isOpen()) {
                throw SalesException::sessionNotOpen();
            }

            if ($session->getAttribute('vendor_id') !== $order->getAttribute('vendor_id')) {
                throw SalesException::unitOutsideVendor();
            }

            $refund = new Refund([
                'method' => $method ?? $order->payments()->value('method') ?? PaymentMethod::Cash,
                'amount_cents' => $amountCents,
                'reason' => trim($reason),
            ]);
            $refund->order_id = $order->id;
            $refund->cash_session_id = $session->id;
            $refund->user_id = $user?->id;
            $refund->save();

            return $refund;
        }, 3);
    }
}
