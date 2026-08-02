<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Cobra una orden abierta y descuenta el inventario por el libro mayor.
 *
 * Concurrencia primero: la orden y su sesión se releen CON LOCK dentro de
 * la transacción — dos terminales cobrando a la vez, una gana y la otra
 * recibe «no está abierta». El backstop es el unique de payments.order_id:
 * un doble cobro que escapara al lock revienta y revierte el stock. El
 * consumo se aplana por insumo y se aplica en orden canónico de id: los
 * locks del ledger se toman siempre igual y no hay abrazo mortal. El
 * reintento ante deadlocks residuales solo puede vivir en la transacción
 * MÁS EXTERNA (aquí somos savepoint cuando nos llaman desde el POS): lo
 * pone PosOrderController.
 *
 * El stock puede quedar negativo a propósito: un POS jamás bloquea la venta
 * por un conteo desfasado — la diferencia la corrige un ajuste.
 */
class PayOrder
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(Order $order, PaymentMethod $method, int $amountCents): Order
    {
        // Fast-fail; la verificación autoritativa va con lock, adentro.
        if ($order->status !== OrderStatus::Open) {
            throw SalesException::orderNotOpen();
        }

        $orderId = $order->id;

        return DB::transaction(function () use ($orderId, $method, $amountCents): Order {
            // Con scopes: una orden de otra cuenta u otro comercio no
            // existe para quien cobra. Con lock: el estado es el comiteado.
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first()
                ?? throw SalesException::orderNotOpen();

            if ($order->status !== OrderStatus::Open) {
                throw SalesException::orderNotOpen();
            }

            if ($amountCents < $order->total_cents) {
                throw SalesException::paymentBelowTotal();
            }

            if ($method !== PaymentMethod::Cash && $amountCents !== $order->total_cents) {
                throw SalesException::exactAmountRequired();
            }

            // El billete entra en una gaveta abierta: la sesión de la orden
            // debe seguir abierta (el lock la serializa contra el cierre).
            $session = CashSession::query()
                ->whereKey($order->cash_session_id)
                ->lockForUpdate()
                ->first();

            if ($session === null || ! $session->isOpen()) {
                throw SalesException::sessionNotOpen();
            }

            $payment = new Payment([
                'method' => $method,
                'amount_cents' => $order->total_cents,
                'tendered_cents' => $amountCents,
                'change_cents' => $amountCents - $order->total_cents,
            ]);
            $payment->order_id = $order->id;
            $payment->save();

            $order->load(['lines.product.recipeItems', 'operatingUnit']);
            $reference = 'venta-'.$order->client_ref;

            // Consumo aplanado por insumo, aplicado en orden canónico de id.
            $consumption = [];

            foreach ($order->lines as $line) {
                $product = $line->product;
                $quantity = (float) $line->quantity;

                if ($product === null) {
                    continue;
                }

                if ($product->type === ProductType::Recipe) {
                    foreach ($product->recipeItems as $ingredient) {
                        $consumption[$ingredient->inventory_item_id] =
                            ($consumption[$ingredient->inventory_item_id] ?? 0.0)
                            + (float) $ingredient->quantity * $quantity;
                    }
                } elseif ($product->track_stock && $product->inventory_item_id !== null) {
                    $consumption[$product->inventory_item_id] =
                        ($consumption[$product->inventory_item_id] ?? 0.0) + $quantity;
                }
            }

            ksort($consumption);

            foreach ($consumption as $itemId => $quantity) {
                $this->ledger->apply(
                    $order->operatingUnit,
                    InventoryItem::query()->findOrFail($itemId),
                    StockMovementType::SaleConsumption,
                    -abs($quantity),
                    null,
                    $reference,
                );
            }

            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])->save();

            return $order;
        }, 3);
    }
}
