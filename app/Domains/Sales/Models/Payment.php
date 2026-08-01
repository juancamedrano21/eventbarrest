<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = ['method', 'amount_cents'];

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

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
