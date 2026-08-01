<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Crea una orden con sus líneas a partir del catálogo vigente, congelando
 * nombre y precio. Idempotente por client_ref: el POS offline puede reenviar
 * la misma orden mil veces y existe una sola.
 *
 * El precio al público ya incluye el ITBIS (18 %): el desglose se calcula
 * hacia adentro. La propina legal (10 %) es opcional y se suma al total.
 */
class PlaceOrder
{
    /**
     * @param  array<int, array{product_id: int, quantity: float|int}>  $lines
     */
    public function __invoke(
        CashSession $session,
        array $lines,
        string $clientRef,
        ?User $user = null,
        bool $withTip = false,
    ): Order {
        if (! $session->isOpen()) {
            throw SalesException::sessionNotOpen();
        }

        if ($lines === []) {
            throw SalesException::orderNeedsLines();
        }

        // Idempotencia por UNIDAD: dos comercios o dos dispositivos con la
        // misma referencia no chocan entre sí.
        $existing = Order::query()
            ->where('operating_unit_id', $session->operating_unit_id)
            ->where('client_ref', $clientRef)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($session, $lines, $clientRef, $user, $withTip);
        } catch (UniqueConstraintViolationException) {
            // Carrera del reenvío offline: otro request la creó primero.
            return Order::query()
                ->where('operating_unit_id', $session->operating_unit_id)
                ->where('client_ref', $clientRef)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int}>  $lines
     */
    private function create(
        CashSession $session,
        array $lines,
        string $clientRef,
        ?User $user,
        bool $withTip,
    ): Order {
        return DB::transaction(function () use ($session, $lines, $clientRef, $user, $withTip): Order {
            $subtotal = 0;
            $prepared = [];

            foreach ($lines as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);

                if (! $product->active) {
                    throw SalesException::productNotSellable($product->name);
                }

                // Normalizada a la precisión de la columna (decimal 10,3):
                // el total se deriva de la MISMA cantidad que se persiste.
                $quantity = round((float) $line['quantity'], 3);

                if ($quantity < 0.001) {
                    throw SalesException::invalidQuantity();
                }

                $total = (int) round($product->price_cents * $quantity);
                $subtotal += $total;

                $prepared[] = [$product, $quantity, $total];
            }

            $itbis = (int) round($subtotal * 18 / 118);
            // Propina legal sobre la BASE, no sobre la base con impuesto.
            $tip = $withTip ? (int) round(($subtotal - $itbis) * 0.10) : 0;

            $order = new Order([
                'client_ref' => $clientRef,
                'status' => OrderStatus::Open,
                'subtotal_cents' => $subtotal,
                'itbis_cents' => $itbis,
                'tip_cents' => $tip,
                'total_cents' => $subtotal + $tip,
            ]);
            $order->operating_unit_id = $session->operating_unit_id;
            $order->cash_session_id = $session->id;
            $order->user_id = $user?->id;
            $order->save();

            foreach ($prepared as [$product, $quantity, $total]) {
                $orderLine = $order->lines()->make([
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price_cents' => $product->price_cents,
                    'total_cents' => $total,
                ]);
                $orderLine->product_id = $product->id;
                $orderLine->save();
            }

            return $order;
        });
    }
}
