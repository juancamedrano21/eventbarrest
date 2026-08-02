<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\SalesChannel;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Crea una orden con sus líneas a partir del catálogo vigente, congelando
 * nombre y precio. Idempotente por client_ref: el POS offline puede reenviar
 * la misma orden mil veces y existe una sola.
 *
 * El ITBIS (18 %) se calcula LÍNEA a LÍNEA — un producto exento (agua,
 * alimentos no gravados) simplemente no aporta — según la modalidad del
 * negocio: incluido en el precio (desglose hacia adentro, el total no
 * crece) o por fuera (se suma al cobrar). La propina legal (10 %) es
 * opcional y siempre se calcula sobre la base sin impuesto.
 *
 * La venta congela su modalidad y la comisión del organizador: ambas
 * pueden cambiar mañana; lo cobrado hoy no se reescribe.
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
        SalesChannel $channel = SalesChannel::Pos,
        ?string $customerName = null,
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
            return $this->create($session, $lines, $clientRef, $user, $withTip, $channel, $customerName);
        } catch (UniqueConstraintViolationException $exception) {
            // Carrera del reenvío offline: otro request la creó primero. Si
            // lo que chocó fue el NÚMERO, el reintento del contador es la
            // salida — disfrazarlo de reenvío escondería el fallo real.
            $gemela = Order::query()
                ->where('operating_unit_id', $session->operating_unit_id)
                ->where('client_ref', $clientRef)
                ->first();

            if ($gemela === null) {
                throw $exception;
            }

            return $gemela;
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
    /**
     * Un nombre para gritar, no un campo de texto libre: se recorta a lo que
     * cabe en la comanda y el vacío es null, no una cadena en blanco que
     * luego imprima una línea sola.
     */
    private function nombreLimpio(?string $name): ?string
    {
        $limpio = trim((string) $name);

        return $limpio === '' ? null : mb_substr($limpio, 0, 60);
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
        SalesChannel $channel,
        ?string $customerName,
    ): Order {
        return DB::transaction(function () use ($session, $lines, $clientRef, $user, $withTip, $channel, $customerName): Order {
            $unit = OperatingUnit::query()->withoutGlobalScopes()
                ->whereKey($session->operating_unit_id)
                ->first(['tenant_id', 'vendor_id', 'event_id']);

            $modo = app(ResolveItbisMode::class)->forVendor(
                $unit?->getAttribute('vendor_id'),
                (int) $unit?->getAttribute('tenant_id'),
            );

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
                $lineItbis = $product->itbis_exempt ? 0 : $modo->itbisOf($total);

                $subtotal += $total;
                $itbis += $lineItbis;

                $prepared[] = [$product, $quantity, $total, $lineItbis];
            }

            // Propina legal sobre la BASE, no sobre la base con impuesto.
            $tip = $withTip ? (int) round($modo->baseWithoutItbis($subtotal, $itbis) * 0.10) : 0;

            $order = new Order([
                'client_ref' => $clientRef,
                // A nombre de quién: lo que se grita cuando el plato sale.
                'customer_name' => $this->nombreLimpio($customerName),
                'status' => OrderStatus::Open,
                'subtotal_cents' => $subtotal,
                'itbis_cents' => $itbis,
                'tip_cents' => $tip,
                'total_cents' => $modo->totalOf($subtotal, $itbis) + $tip,
            ]);
            $order->operating_unit_id = $session->operating_unit_id;
            $order->cash_session_id = $session->id;
            $order->user_id = $user?->id;
            $order->commission_bps = $this->commissionFor($unit);
            $order->itbis_mode = $modo;
            $order->channel = $channel;

            // El número que el cliente dicta: serie POR COMERCIO (por
            // cuenta si no hay comercio), tomada con lock aquí dentro.
            $tenantId = (int) $unit?->getAttribute('tenant_id');
            $vendorId = $unit?->getAttribute('vendor_id');
            $order->number_scope = $vendorId ?? 0;
            $order->order_number = app(NextOrderNumber::class)($tenantId, $vendorId);
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
    private function commissionFor(?OperatingUnit $unit): ?int
    {
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
