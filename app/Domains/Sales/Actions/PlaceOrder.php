<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\Operations\Models\OperatingUnit;
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
 * hacia adentro, LÍNEA a LÍNEA — un producto exento (agua, alimentos no
 * gravados) simplemente no aporta. La propina legal (10 %) es opcional y se
 * calcula sobre la base sin impuesto.
 *
 * La venta también congela la comisión del organizador: la participación
 * puede renegociarse mañana, lo cobrado hoy no se reescribe.
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
        if ($lines === []) {
            throw SalesException::orderNeedsLines();
        }

        // El lookup idempotente va ANTES del guard de sesión: el reenvío de
        // una venta ya registrada devuelve su estado aunque la caja haya
        // cerrado. Y devuelve la MISMA venta o nada: otra sesión u otro
        // contenido con la misma referencia es un error operable, no un
        // éxito silencioso sobre una orden distinta.
        $existing = Order::query()
            ->where('operating_unit_id', $session->operating_unit_id)
            ->where('client_ref', $clientRef)
            ->first();

        if ($existing !== null) {
            $this->assertSameSale($existing, $session, $lines, $withTip);

            return $existing;
        }

        if (! $session->isOpen()) {
            throw SalesException::sessionNotOpen();
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
     * El reenvío legítimo trae exactamente lo mismo; cualquier divergencia
     * (sesión, líneas o propina) delata una referencia reutilizada.
     *
     * @param  array<int, array{product_id: int, quantity: float|int}>  $lines
     */
    private function assertSameSale(Order $existing, CashSession $session, array $lines, bool $withTip): void
    {
        $sent = collect($lines)
            ->map(fn (array $line): string => (int) $line['product_id'].':'.round((float) $line['quantity'], 3))
            ->sort()->values()->implode('|');

        $stored = $existing->lines()
            ->get(['product_id', 'quantity'])
            ->map(fn ($line): string => (int) $line->product_id.':'.round((float) $line->quantity, 3))
            ->sort()->values()->implode('|');

        if ($existing->cash_session_id !== $session->id
            || $sent !== $stored
            || $withTip !== ($existing->tip_cents > 0)) {
            throw SalesException::clientRefReused();
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
            $itbis = 0;
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
                // El desglose es POR LÍNEA: los exentos no aportan y el
                // redondeo por línea es el que irá al comprobante fiscal.
                $lineItbis = $product->itbis_exempt ? 0 : (int) round($total * 18 / 118);

                $subtotal += $total;
                $itbis += $lineItbis;

                $prepared[] = [$product, $quantity, $total, $lineItbis];
            }

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
            $order->commission_bps = $this->commissionFor($session);
            $order->save();

            foreach ($prepared as [$product, $quantity, $total, $lineItbis]) {
                $orderLine = $order->lines()->make([
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price_cents' => $product->price_cents,
                    'total_cents' => $total,
                    'itbis_cents' => $lineItbis,
                ]);
                $orderLine->product_id = $product->id;
                $orderLine->save();
            }

            return $order;
        });
    }

    /**
     * La comisión pactada HOY para el puesto que vende, congelada en la
     * orden. Null en el mundo negocio (sucursales sin evento). Sin scopes:
     * el guard decide con la verdad de las filas, no la vista del contexto.
     */
    private function commissionFor(CashSession $session): ?int
    {
        $unit = OperatingUnit::query()->withoutGlobalScopes()
            ->whereKey($session->operating_unit_id)
            ->first(['tenant_id', 'vendor_id', 'event_id']);

        if ($unit === null || $unit->event_id === null || $unit->getAttribute('vendor_id') === null) {
            return null;
        }

        $bps = EventVendor::query()->withoutGlobalScopes()
            ->where('tenant_id', $unit->getAttribute('tenant_id'))
            ->where('event_id', $unit->event_id)
            ->where('vendor_id', $unit->getAttribute('vendor_id'))
            ->value('commission_bps');

        return $bps === null ? null : (int) $bps;
    }
}
