<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una venta. Los totales viven en centavos y el desglose de ITBIS es
 * informativo (el precio al público ya lo incluye, como se vende en RD).
 *
 * Cobrada o anulada, la orden es historia: el guard de updating solo deja
 * pasar la transición de anulación sobre una cobrada, y nada sobre una
 * anulada.
 *
 * @property int $id
 * @property int $operating_unit_id
 * @property int $cash_session_id
 * @property string $client_ref
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $itbis_cents
 * @property int $tip_cents
 * @property int $total_cents
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
class Order extends Model
{
    use BelongsToTenant;

    protected $fillable = ['client_ref', 'status', 'subtotal_cents', 'itbis_cents', 'tip_cents', 'total_cents'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'itbis_cents' => 'integer',
            'tip_cents' => 'integer',
            'total_cents' => 'integer',
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
        });

        static::updating(function (Order $order): void {
            $original = OrderStatus::from((string) $order->getRawOriginal('status'));

            if ($original === OrderStatus::Void) {
                throw SalesException::paidOrdersAreHistory();
            }

            if ($original === OrderStatus::Paid) {
                $allowed = ['status', 'voided_at', 'void_reason'];

                if (array_diff(array_keys($order->getDirty()), $allowed) !== []) {
                    throw SalesException::paidOrdersAreHistory();
                }
            }
        });

        static::deleting(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });
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
