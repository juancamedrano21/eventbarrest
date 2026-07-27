<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Eloquent\StockLevelBuilder;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Proyección del libro de movimientos: la existencia actual de un insumo en
 * una unidad. La mantiene StockLedger dentro de una transacción con lock —
 * nunca se escribe a mano.
 *
 * @property int $operating_unit_id
 * @property int $inventory_item_id
 * @property numeric-string $quantity
 * @property numeric-string|null $alert_threshold
 * @property-read OperatingUnit|null $operatingUnit
 * @property-read InventoryItem|null $inventoryItem
 */
class StockLevel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'alert_threshold',
    ];

    /** Solo StockLedger enciende esta bandera, vía saveProjection(). */
    private bool $projectionWrite = false;

    protected static function booted(): void
    {
        // La cantidad solo la escribe el libro. Todo lo demás (umbral) es
        // editable con normalidad.
        $guard = function (StockLevel $level): void {
            if ($level->isDirty('quantity') && ! $level->projectionWrite) {
                throw InventoryException::projectionIsLedgerOnly();
            }
        };

        static::creating($guard);
        static::updating($guard);

        static::deleting(function (): void {
            throw InventoryException::projectionIsLedgerOnly();
        });
    }

    /**
     * El save() de instancia pasa por el query builder, así que el guard de
     * masivos necesita distinguir la escritura legítima del libro.
     */
    public function isProjectionWrite(): bool
    {
        return $this->projectionWrite;
    }

    /**
     * La única puerta por la que StockLedger escribe la cantidad, dentro de
     * su transacción con lock.
     */
    public function saveProjection(): void
    {
        $this->projectionWrite = true;

        try {
            $this->save();
        } finally {
            $this->projectionWrite = false;
        }
    }

    /**
     * @param  QueryBuilder  $query
     * @return StockLevelBuilder<*>
     */
    public function newEloquentBuilder($query): StockLevelBuilder
    {
        return new StockLevelBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'alert_threshold' => 'decimal:3',
        ];
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

    public function isLow(): bool
    {
        return $this->alert_threshold !== null
            && (float) $this->quantity <= (float) $this->alert_threshold;
    }
}
