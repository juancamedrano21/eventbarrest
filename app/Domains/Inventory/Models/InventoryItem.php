<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un insumo: lo que se compra y se consume. Su costo es por unidad base y
 * alimenta el escandallo de los productos con receta.
 *
 * @property int $id
 * @property string $name
 * @property MeasurementUnit $base_unit
 * @property int $cost_cents costo por unidad base, en centavos
 */
class InventoryItem extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'base_unit',
        'cost_cents',
    ];

    protected function casts(): array
    {
        return [
            'base_unit' => MeasurementUnit::class,
            'cost_cents' => 'integer',
        ];
    }

    protected static function newFactory(): InventoryItemFactory
    {
        return InventoryItemFactory::new();
    }
}
