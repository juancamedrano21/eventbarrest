<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Sales\Eloquent\SalesHistoryBuilder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * Un cobro aplicado a una orden. Inmutable: los errores se corrigen anulando
 * la orden, nunca editando el dinero.
 *
 * @property int $order_id
 * @property PaymentMethod $method
 * @property int $amount_cents
 */
class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['method', 'amount_cents', 'tendered_cents', 'change_cents'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'tendered_cents' => 'integer',
            'change_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Un cobro pertenece a una orden de SU misma cuenta.
        static::creating(function (Payment $payment): void {
            if ($payment->getAttribute('tenant_id') === null) {
                return;
            }

            $orderTenant = Order::query()->withoutGlobalScopes()
                ->whereKey($payment->order_id)
                ->value('tenant_id');

            if ($orderTenant !== $payment->getAttribute('tenant_id')) {
                throw SalesException::unitOutsideTenant();
            }
        });

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
}
