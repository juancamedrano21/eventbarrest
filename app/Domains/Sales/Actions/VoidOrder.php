<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Anula una orden ABIERTA (no cobrada): nada que devolver al inventario
 * porque el stock se descuenta al cobrar. Relee con lock y con scopes: el
 * estado es el comiteado y una orden ajena no existe para quien anula. La
 * anulación de órdenes cobradas llegará como reembolso contable explícito.
 */
class VoidOrder
{
    public function __invoke(Order $order, string $reason): Order
    {
        $orderId = $order->id;

        return DB::transaction(function () use ($orderId, $reason): Order {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first()
                ?? throw SalesException::orderNotOpen();

            if ($order->status !== OrderStatus::Open) {
                throw SalesException::orderNotOpen();
            }

            $order->forceFill([
                'status' => OrderStatus::Void,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $order;
        });
    }
}
