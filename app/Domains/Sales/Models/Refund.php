<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Sales\Eloquent\SalesHistoryBuilder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * Dinero devuelto de una venta cobrada. Es un hecho nuevo, no una edición
 * del pasado: nace y ya no cambia — corregir un reembolso equivocado será
 * otro asiento, nunca un update.
 *
 * @property int $order_id
 * @property int $cash_session_id
 * @property PaymentMethod $method
 * @property int $amount_cents
 * @property string $reason
 */
class Refund extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    protected $fillable = ['method', 'amount_cents', 'reason'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });

        static::deleting(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });
    }

    /**
     * @param  Builder  $query
     * @return SalesHistoryBuilder<*>
     */
    public function newEloquentBuilder($query): SalesHistoryBuilder
    {
        return new SalesHistoryBuilder($query);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
