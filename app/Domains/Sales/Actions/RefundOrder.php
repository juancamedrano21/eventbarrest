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

            // La gaveta de la que sale el dinero tiene que estar abierta, y
            // ser la de la MISMA unidad donde se vendió: si no, el arqueo
            // del descuadre se le imputaría a un cajero que no vendió eso.
            // (Comparar solo vendor_id no basta: en el mundo negocio ambos
            // son null y el guard no protegería nada.)
            $session = CashSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            if ($session === null || ! $session->isOpen()) {
                throw SalesException::sessionNotOpen();
            }

            if ($session->operating_unit_id !== $order->operating_unit_id) {
                throw SalesException::refundBelongsToAnotherUnit();
            }

            // Se devuelve por donde se cobró: sacar efectivo de una venta
            // con tarjeta vacía una gaveta donde ese dinero nunca entró.
            $metodo = $method ?? $order->payments()->value('method') ?? PaymentMethod::Cash;
            $this->assertMethodWasCharged($order, $metodo, $amountCents);

            $refund = new Refund([
                'method' => $metodo,
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

    /**
     * No se devuelve por un método más de lo que entró por él: el arqueo
     * de la gaveta depende de que el efectivo que sale haya entrado.
     */
    private function assertMethodWasCharged(Order $order, PaymentMethod $method, int $amountCents): void
    {
        $cobrado = (int) $order->payments()->where('method', $method->value)->sum('amount_cents');
        $devuelto = (int) Refund::query()
            ->where('order_id', $order->id)
            ->where('method', $method->value)
            ->sum('amount_cents');

        if ($amountCents > $cobrado - $devuelto) {
            throw SalesException::refundMethodMismatch($method->getLabel(), max(0, $cobrado - $devuelto));
        }
    }
}
