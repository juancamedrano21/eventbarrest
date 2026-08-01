<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una jornada de caja en una unidad: abre con fondo, acumula ventas y cierra
 * contra lo contado. Solo puede haber UNA abierta por unidad (índice único
 * sobre columna generada).
 *
 * @property int $id
 * @property int $operating_unit_id
 * @property CashSessionStatus $status
 * @property int $opening_cents
 * @property int|null $closing_cents
 * @property int|null $expected_cents
 * @property int|null $difference_cents
 */
class CashSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['status', 'opening_cents', 'opened_at'];

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_cents' => 'integer',
            'closing_cents' => 'integer',
            'expected_cents' => 'integer',
            'difference_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CashSession $session): void {
            if ($session->getAttribute('tenant_id') === null) {
                return;
            }

            $unitTenant = OperatingUnit::query()->withoutGlobalScopes()
                ->whereKey($session->operating_unit_id)
                ->value('tenant_id');

            if ($unitTenant !== $session->getAttribute('tenant_id')) {
                throw SalesException::unitOutsideTenant();
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    /** @return BelongsTo<OperatingUnit, $this> */
    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
