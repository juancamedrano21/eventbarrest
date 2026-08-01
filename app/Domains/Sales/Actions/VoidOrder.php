<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Order;

/**
 * Anula una orden ABIERTA (no cobrada): nada que devolver al inventario
 * porque el stock se descuenta al cobrar. La anulación de órdenes cobradas
 * (con reposición de stock) llegará con su permiso sales.void en el POS.
 */
class VoidOrder
{
    public function __invoke(Order $order, string $reason): Order
    {
        if ($order->status !== OrderStatus::Open) {
            throw SalesException::orderNotOpen();
        }

        $order->forceFill([
            'status' => OrderStatus::Void,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $order;
    }
}
