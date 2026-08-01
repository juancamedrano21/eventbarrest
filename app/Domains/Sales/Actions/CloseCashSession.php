<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Cierra la caja contra lo contado: lo esperado es el fondo inicial más el
 * EFECTIVO cobrado en la sesión (tarjeta y transferencia no viven en la
 * gaveta). Con lock: una venta en vuelo espera o queda en el arqueo, nunca
 * a medias. Con órdenes abiertas no se cierra: se cobran o se anulan —
 * ninguna orden sobrevive a su sesión, y el efectivo jamás queda huérfano.
 */
class CloseCashSession
{
    public function __invoke(CashSession $session, int $countedCents): CashSession
    {
        $sessionId = $session->id;

        return DB::transaction(function () use ($sessionId, $countedCents): CashSession {
            $session = CashSession::query()->whereKey($sessionId)->lockForUpdate()->first()
                ?? throw SalesException::sessionNotOpen();

            if (! $session->isOpen()) {
                throw SalesException::sessionNotOpen();
            }

            if ($session->orders()->where('status', OrderStatus::Open->value)->exists()) {
                throw SalesException::sessionHasOpenOrders();
            }

            $cash = (int) Payment::query()
                ->where('method', PaymentMethod::Cash->value)
                ->whereIn('order_id', $session->orders()
                    ->where('status', OrderStatus::Paid->value)
                    ->select('id'))
                ->sum('amount_cents');

            $expected = $session->opening_cents + $cash;

            $session->forceFill([
                'status' => CashSessionStatus::Closed,
                'closing_cents' => $countedCents,
                'expected_cents' => $expected,
                'difference_cents' => $countedCents - $expected,
                'closed_at' => now(),
            ])->save();

            return $session;
        });
    }
}
