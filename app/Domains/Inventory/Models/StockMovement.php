<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Eloquent\StockMovementBuilder;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Una línea del libro mayor de inventario. Inmutable: no se edita ni se
 * borra — un error se corrige con un ajuste, dejando rastro. Se crea
 * únicamente a través de StockLedger, que mantiene la proyección de
 * existencias en la misma transacción.
 *
 * @property int $operating_unit_id
 * @property int $inventory_item_id
 * @property StockMovementType $type
 * @property numeric-string $quantity con signo
 * @property int|null $unit_cost_cents
 * @property string|null $reference
 * @property int|null $user_id
 * @property-read OperatingUnit|null $operatingUnit
 * @property-read InventoryItem|null $inventoryItem
 * @property-read User|null $user
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'operating_unit_id',
        'inventory_item_id',
        'type',
        'quantity',
        'unit_cost_cents',
        'reference',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            $quantity = (float) $movement->quantity;

            if ($quantity === 0.0) {
                throw InventoryException::quantityCannotBeZero();
            }

            $direction = $movement->type->direction();

            if (($direction === 1 && $quantity < 0) || ($direction === -1 && $quantity > 0)) {
                throw InventoryException::wrongSign($movement->type);
            }

            if ($movement->getAttribute('tenant_id') === null) {
                return;
            }

            // Sin scope a propósito: queremos saber de quién son las filas
            // de verdad, no si podemos verlas.
            $unitTenant = OperatingUnit::query()->withoutTenancy()
                ->whereKey($movement->operating_unit_id)
                ->value('tenant_id');

            if ($unitTenant !== $movement->tenant_id) {
                throw InventoryException::unitOutsideTenant();
            }

            $itemTenant = InventoryItem::query()->withoutTenancy()
                ->whereKey($movement->inventory_item_id)
                ->value('tenant_id');

            if ($itemTenant !== $movement->tenant_id) {
                throw InventoryException::itemOutsideTenant();
            }
        });

        static::updating(function (): void {
            throw InventoryException::ledgerIsImmutable();
        });

        static::deleting(function (): void {
            throw InventoryException::ledgerIsImmutable();
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return StockMovementBuilder<*>
     */
    public function newEloquentBuilder($query): StockMovementBuilder
    {
        return new StockMovementBuilder($query);
    }

    /**
     * @return BelongsTo<OperatingUnit, $this>
     */
    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
