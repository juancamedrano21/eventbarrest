<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Enums\CommissionBase;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\SalesChannel;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
     * @param  array<int, array{product_id: int, quantity: float|int, notes?: string|null}>  $lines
     */
    public function __invoke(
        CashSession $session,
        array $lines,
        string $clientRef,
        ?User $user = null,
        bool $withTip = false,
        SalesChannel $channel = SalesChannel::Pos,
        ?string $customerName = null,
        ?CarbonInterface $soldAt = null,
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
            return $this->create($session, $lines, $clientRef, $user, $withTip, $channel, $customerName, $soldAt);
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
     * Lo que se compara es el hecho ECONÓMICO: qué se vendió y por cuánto.
     * La nota de preparación y la hora del dispositivo quedan fuera a
     * propósito — un borrador guardado antes de que existieran esos campos
     * se reenviaría distinto sin que nadie haya reutilizado nada.
     *
     * @param  array<int, array{product_id: int, quantity: float|int, notes?: string|null}>  $lines
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
     * Una instrucción para quien cocina, no un campo libre: se recorta a lo
     * que cabe en una tarjeta que se lee a tres metros.
     */
    private function notaLimpia(?string $notes): ?string
    {
        $limpia = trim((string) $notes);

        return $limpia === '' ? null : mb_substr($limpia, 0, 120);
    }

    /**
     * La hora que dice el dispositivo, si es creíble. El reloj de una tablet
     * barata se desfasa: una marca futura o de hace más de un día no es un
     * retraso de sincronización, es un reloj mal puesto, y pintar con ella
     * la espera del cliente daría cifras absurdas. Ante la duda, null — y el
     * tablero cae a paid_at, que siempre es del servidor.
     */
    private function horaCreible(?CarbonInterface $soldAt): ?Carbon
    {
        if ($soldAt === null) {
            return null;
        }

        $ahora = Carbon::now();

        return $soldAt->greaterThan($ahora->copy()->addMinutes(5)) || $soldAt->lessThan($ahora->copy()->subDay())
            ? null
            : Carbon::instance($soldAt);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int, notes?: string|null}>  $lines
     */
    private function create(
        CashSession $session,
        array $lines,
        string $clientRef,
        ?User $user,
        bool $withTip,
        SalesChannel $channel,
        ?string $customerName,
        ?CarbonInterface $soldAt,
    ): Order {
        return DB::transaction(function () use ($session, $lines, $clientRef, $user, $withTip, $channel, $customerName, $soldAt): Order {
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
                $product = Product::query()->with('category')->findOrFail((int) $line['product_id']);

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

                $prepared[] = [$product, $quantity, $total, $lineItbis, $this->notaLimpia($line['notes'] ?? null)];
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
            // La BASE se congela igual que el porcentaje: cambiar el ajuste
            // de la cuenta rige de aqui en adelante, nunca hacia atras.
            $order->commission_base = $this->commissionBaseFor($unit);
            $order->itbis_mode = $modo;
            $order->channel = $channel;
            // La hora del cajero, para saber si esta venta llega con retraso
            // de sincronización. paid_at la pone el servidor al cobrar.
            $order->device_sold_at = $this->horaCreible($soldAt);

            // El número que el cliente dicta: serie POR COMERCIO (por
            // cuenta si no hay comercio), tomada con lock aquí dentro.
            $tenantId = (int) $unit?->getAttribute('tenant_id');
            $vendorId = $unit?->getAttribute('vendor_id');
            $order->number_scope = $vendorId ?? 0;
            $order->order_number = app(NextOrderNumber::class)($tenantId, $vendorId);
            $order->save();

            foreach ($prepared as [$product, $quantity, $total, $lineItbis, $nota]) {
                $orderLine = $order->lines()->make([
                    'product_name' => $product->name,
                    // De dónde sale esto: barra o cocina. Vive en la
                    // categoría, que es mutable — congelarlo aquí impide que
                    // recategorizar mañana reescriba qué se hizo hoy.
                    'dispatch' => $product->category->dispatch,
                    'notes' => $nota,
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
    /**
     * Con qué regla se calcula la comisión de ESTA venta. Solo tiene sentido
     * donde hay comisión: en el mundo del negocio no hay organizador que
     * cobre nada.
     */
    private function commissionBaseFor(?OperatingUnit $unit): ?CommissionBase
    {
        if ($unit === null || $unit->event_id === null || $unit->getAttribute('vendor_id') === null) {
            return null;
        }

        $valor = DB::table('tenants')
            ->where('id', $unit->getAttribute('tenant_id'))
            ->value('commission_base');

        return CommissionBase::tryFrom((string) $valor) ?? CommissionBase::Total;
    }

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
