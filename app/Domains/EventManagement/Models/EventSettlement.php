<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\EventManagement\Enums\CommissionBase;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El estado de cuenta congelado de un comercio en un evento.
 *
 * Las cifras no se tocan una vez escritas: son el documento sobre el que se
 * paga. Lo único que se anota después es el cobro de la comisión.
 *
 * @property int $orders_count
 * @property int $gross_cents
 * @property int $refunded_cents
 * @property int $tip_cents
 * @property int $itbis_cents
 * @property CommissionBase $commission_base
 * @property int $commission_bps
 * @property int $commission_base_cents
 * @property int $commission_cents
 * @property int $net_cents
 */
class EventSettlement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'orders_count',
        'gross_cents',
        'refunded_cents',
        'tip_cents',
        'itbis_cents',
        'commission_base',
        'commission_bps',
        'commission_base_cents',
        'commission_cents',
        'net_cents',
        'settled_at',
        'settled_by',
        'paid_at',
        'paid_by',
        'payment_note',
    ];

    protected function casts(): array
    {
        return [
            'commission_base' => CommissionBase::class,
            'orders_count' => 'integer',
            'gross_cents' => 'integer',
            'refunded_cents' => 'integer',
            'tip_cents' => 'integer',
            'itbis_cents' => 'integer',
            'commission_bps' => 'integer',
            'commission_base_cents' => 'integer',
            'commission_cents' => 'integer',
            'net_cents' => 'integer',
            'settled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** El porcentaje pactado, para enseñarlo sin que nadie divida a mano. */
    public function commissionPercent(): float
    {
        return round($this->commission_bps / 100, 2);
    }
}
