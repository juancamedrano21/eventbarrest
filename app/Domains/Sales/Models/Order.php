<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Eloquent\SalesHistoryBuilder;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Una venta. Los totales viven en centavos y el ITBIS se suma de las
 * líneas, donde los exentos aportan cero. Según la modalidad congelada
 * (itbis_mode) el impuesto va DENTRO del precio — desglose informativo,
 * el total no crece — o POR FUERA, sumado al total al cobrar.
 *
 * Cobrada o anulada, la orden es historia: el guard de updating no deja
 * tocar ninguna de las dos — la anulación post-cobro llegará como
 * reembolso contable, nunca como edición del pasado.
 *
 * commission_bps congela la comisión del organizador pactada al vender
 * (null en el mundo negocio): renegociarla después no reescribe historia.
 *
 * @property int $id
 * @property int $operating_unit_id
 * @property int $cash_session_id
 * @property int|null $user_id
 * @property string $client_ref
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $itbis_cents
 * @property int $tip_cents
 * @property int $total_cents
 * @property int|null $commission_bps
 * @property ItbisMode $itbis_mode
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
class Order extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    protected $fillable = ['client_ref', 'status', 'subtotal_cents', 'itbis_cents', 'tip_cents', 'total_cents'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'itbis_cents' => 'integer',
            'tip_cents' => 'integer',
            'total_cents' => 'integer',
            'commission_bps' => 'integer',
            'itbis_mode' => ItbisMode::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if ($order->getAttribute('tenant_id') === null) {
                return;
            }

            $unitTenant = OperatingUnit::query()->withoutGlobalScopes()
                ->whereKey($order->operating_unit_id)
                ->value('tenant_id');

            if ($unitTenant !== $order->getAttribute('tenant_id')) {
                throw SalesException::unitOutsideTenant();
            }

            $unitVendor = OperatingUnit::query()->withoutGlobalScopes()
                ->whereKey($order->operating_unit_id)
                ->value('vendor_id');

            if ($unitVendor !== $order->getAttribute('vendor_id')) {
                throw SalesException::unitOutsideVendor();
            }
        });

        static::updating(function (Order $order): void {
            $original = OrderStatus::from((string) $order->getRawOriginal('status'));

            if ($original === OrderStatus::Void) {
                throw SalesException::paidOrdersAreHistory();
            }

            if ($original === OrderStatus::Paid) {
                throw SalesException::paidOrdersAreHistory();
            }
        });

        static::deleting(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });
    }

    /**
     * El guard de updating solo ve los save de modelo; un update acotado por
     * clave (Order::query()->whereKey($id)->update([...])) los esquiva. El
     * builder pregunta aquí por la fila REAL antes de dejar escribir, así
     * que una venta cobrada o anulada es historia por las dos vías.
     */
    public function assertRowIsWritable(mixed $key): void
    {
        if ($key === null) {
            return;
        }

        $status = static::query()->withoutGlobalScopes()
            ->getQuery()  // crudo: sin este builder, no hay recursión
            ->where($this->getKeyName(), $key)
            ->value('status');

        if ($status === null) {
            return;
        }

        if (OrderStatus::tryFrom((string) $status) !== OrderStatus::Open) {
            throw SalesException::paidOrdersAreHistory();
        }
    }

    /**
     * @param  Builder  $query
     * @return SalesHistoryBuilder<*>
     */
    public function newEloquentBuilder($query): SalesHistoryBuilder
    {
        return new SalesHistoryBuilder($query);
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<OperatingUnit, $this> */
    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    /** @return BelongsTo<CashSession, $this> */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
