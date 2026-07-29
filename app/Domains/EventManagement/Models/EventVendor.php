<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La participación de un negocio en un evento. Es donde vive la comisión,
 * porque un mismo negocio puede ir a dos festivales con condiciones
 * distintas, y donde colgará la liquidación de cada edición.
 *
 * @property int $event_id
 * @property int $vendor_id
 * @property int $commission_bps comisión en puntos básicos (1000 = 10%)
 * @property-read Event $event
 * @property-read Vendor $vendor
 */
class EventVendor extends Model
{
    use BelongsToTenant;

    protected $table = 'event_vendor';

    protected $fillable = [
        'commission_bps',
    ];

    protected function casts(): array
    {
        return [
            'commission_bps' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Los puntos de venta de este negocio en este evento.
     *
     * @return HasMany<OperatingUnit, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(OperatingUnit::class, 'event_id', 'event_id')
            ->where('vendor_id', $this->vendor_id);
    }

    /** La comisión como porcentaje, para mostrar. */
    public function commissionPercent(): float
    {
        return round($this->commission_bps / 100, 2);
    }
}
