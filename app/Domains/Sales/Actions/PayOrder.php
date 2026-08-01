<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Cobra una orden abierta y descuenta el inventario por el libro mayor: los
 * productos simples con insumo vinculado bajan su insumo; los de receta
 * bajan cada ingrediente por el escandallo.
 *
 * El stock puede quedar en negativo a propósito: un POS jamás bloquea la
 * venta por un conteo desfasado — la diferencia la corrige un ajuste.
 */
class PayOrder
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(Order $order, PaymentMethod $method, int $amountCents): Order
    {
        if ($order->status !== OrderStatus::Open) {
            throw SalesException::orderNotOpen();
        }

        if ($amountCents < $order->total_cents) {
            throw SalesException::paymentBelowTotal();
        }

        return DB::transaction(function () use ($order, $method): Order {
            $payment = new Payment([
                'method' => $method,
                'amount_cents' => $order->total_cents,
            ]);
            $payment->order_id = $order->id;
            $payment->save();

            $order->load(['lines.product.recipeItems', 'operatingUnit']);
            $reference = 'venta-'.$order->client_ref;

            foreach ($order->lines as $line) {
                $product = $line->product;
                $quantity = (float) $line->quantity;

                if ($product === null) {
                    continue;
                }

                if ($product->type === ProductType::Recipe) {
                    foreach ($product->recipeItems as $ingredient) {
                        $this->ledger->apply(
                            $order->operatingUnit,
                            $ingredient->inventoryItem,
                            StockMovementType::SaleConsumption,
                            -abs((float) $ingredient->quantity * $quantity),
                            null,
                            $reference,
                        );
                    }

                    continue;
                }

                if ($product->track_stock && $product->inventoryItem !== null) {
                    $this->ledger->apply(
                        $order->operatingUnit,
                        $product->inventoryItem,
                        StockMovementType::SaleConsumption,
                        -abs($quantity),
                        null,
                        $reference,
                    );
                }
            }

            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])->save();

            return $order;
        });
    }
}
