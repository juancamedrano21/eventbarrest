<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\EventSettlement;
use App\Domains\EventManagement\Queries\SettlementFigures;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cierra la cuenta de un evento: calcula lo de cada comercio y lo GUARDA.
 *
 * A partir de aquí el estado de cuenta es un documento, no una consulta. Si
 * se recalculara al abrir la pantalla, un reembolso tardío movería una cuenta
 * ya pagada de mano — el mismo motivo por el que una venta cobrada no se
 * edita.
 *
 * Liquidar exige que no quede nada abierto: ni cajas ni órdenes sin cobrar.
 * Una caja abierta significa dinero que todavía puede entrar, y liquidar
 * sobre eso es cerrar una cuenta que aún se mueve.
 */
class SettleEvent
{
    public function __invoke(Event $event, ?User $user = null): int
    {
        if ($event->status === EventStatus::Settled) {
            throw VendorException::eventAlreadySettled();
        }

        $puestos = EventOutlet::query()->where('event_id', $event->id)->pluck('id');

        if (CashSession::query()
            ->whereIn('operating_unit_id', $puestos)
            ->where('status', CashSessionStatus::Open->value)
            ->exists()) {
            throw VendorException::eventHasOpenCashSessions();
        }

        if (Order::query()
            ->whereIn('operating_unit_id', $puestos)
            ->where('status', OrderStatus::Open->value)
            ->exists()) {
            throw VendorException::eventHasOpenOrders();
        }

        return DB::transaction(function () use ($event, $user): int {
            $cifras = app(SettlementFigures::class)->forEvent($event);

            foreach ($cifras as $fila) {
                $settlement = new EventSettlement([
                    'orders_count' => $fila->ordersCount,
                    'gross_cents' => $fila->grossCents,
                    'refunded_cents' => $fila->refundedCents,
                    'tip_cents' => $fila->tipCents,
                    'itbis_cents' => $fila->itbisCents,
                    'commission_base' => $fila->commissionBase,
                    'commission_bps' => $fila->commissionBps,
                    'commission_base_cents' => $fila->commissionBaseCents,
                    'commission_cents' => $fila->commissionCents,
                    'net_cents' => $fila->netCents,
                    'settled_at' => now(),
                    'settled_by' => $user?->id,
                ]);
                $settlement->event_id = $event->id;
                $settlement->vendor_id = $fila->vendorId;
                $settlement->save();
            }

            $event->status = EventStatus::Settled;
            $event->save();

            return $cifras->count();
        });
    }
}
