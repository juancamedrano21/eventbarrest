<?php

declare(strict_types=1);

namespace App\Domains\Operations\Models;

use App\Domains\Business\Models\Branch;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Operations\Eloquent\OperatingUnitBuilder;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Support\Eloquent\HasChildModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * La base neutral de la operación: todo lo transaccional (ventas, stock,
 * cajas, terminales) cuelga de una unidad operativa, así que POS e
 * inventario se construyen una sola vez para los dos mundos.
 *
 * Esta clase no conoce las reglas de ningún mundo. Cada mundo tiene su
 * propio modelo — Branch (Business) y EventOutlet (EventManagement) — y es
 * ahí donde vive su comportamiento. Consultar por la base devuelve
 * instancias del mundo correcto (STI), útil para reportería transversal.
 *
 * @property int $id
 * @property int|null $event_id
 * @property OperatingUnitType $type
 * @property OperatingUnitKind $kind
 * @property string $name
 * @property OperatingUnitStatus $status
 */
class OperatingUnit extends Model
{
    use BelongsToTenant;
    use HasChildModels;

    protected $table = 'operating_units';

    protected $fillable = [
        'name',
        'kind',
        'status',
    ];

    public static function childTypes(): array
    {
        return [
            OperatingUnitType::Branch->value => Branch::class,
            OperatingUnitType::EventOutlet->value => EventOutlet::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => OperatingUnitType::class,
            'kind' => OperatingUnitKind::class,
            'status' => OperatingUnitStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // La base es una vista, no un mundo: las altas nacen en Branch o
        // EventOutlet, nunca aquí.
        static::creating(function (OperatingUnit $unit): void {
            if ($unit::class === self::class) {
                throw InvalidOperatingUnitException::baseIsNotCreatable();
            }
        });

        // Una unidad no cambia de mundo ni de evento: si dejó de operar se
        // cierra por estado. Simétrico con tenant_id, también inmutable.
        static::updating(function (OperatingUnit $unit): void {
            if ($unit->isDirty('event_id') || $unit->isDirty('type')) {
                throw InvalidOperatingUnitException::structureIsImmutable();
            }
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return OperatingUnitBuilder<*>
     */
    public function newEloquentBuilder($query): OperatingUnitBuilder
    {
        return new OperatingUnitBuilder($query);
    }
}
