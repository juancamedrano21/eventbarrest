<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Payment;

/**
 * Cierra la caja contra lo contado: lo esperado es el fondo inicial más el
 * EFECTIVO cobrado en la sesión (tarjeta y transferencia no viven en la
 * gaveta), y la diferencia queda registrada con su signo.
 */
class CloseCashSession
{
    public function __invoke(CashSession $session, int $countedCents): CashSession
    {
        if (! $session->isOpen()) {
            throw SalesException::sessionNotOpen();
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
    }
}
