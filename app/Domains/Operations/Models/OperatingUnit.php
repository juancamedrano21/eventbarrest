<?php

declare(strict_types=1);

namespace App\Domains\Operations\Models;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Eloquent\OperatingUnitBuilder;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\OperatingUnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Sucursal de un negocio o punto de venta dentro de un evento. Todo lo
 * transaccional cuelga de aquí, así que POS, inventario, cajas y reportería
 * se construyen una sola vez para los dos mundos.
 *
 * Las reglas que separan esos mundos viven en el modelo, no en las acciones:
 * una acción es una fachada cómoda, pero cualquier seeder, importador o job
 * futuro escribe por aquí.
 *
 * @property int $id
 * @property int|null $event_id
 * @property OperatingUnitType|null $type Nulo solo antes de guardar: lo deriva el hook saving
 * @property OperatingUnitKind $kind
 * @property string $name
 * @property OperatingUnitStatus $status
 * @property-read Event|null $event
 */
class OperatingUnit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OperatingUnitFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'kind',
        'status',
    ];

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
        // El tipo no se elige suelto: se deriva de si cuelga o no de un evento.
        static::saving(function (OperatingUnit $unit): void {
            $expected = $unit->event_id === null
                ? OperatingUnitType::Branch
                : OperatingUnitType::EventOutlet;

            if ($unit->type !== null && $unit->type !== $expected) {
                throw InvalidOperatingUnitException::typeMismatch($expected, $unit->type);
            }

            $unit->type = $expected;
        });

        // Coherencia con el mundo de la cuenta. Corre en creating (no en
        // saving) porque BelongsToTenant rellena tenant_id justo antes.
        static::creating(function (OperatingUnit $unit): void {
            $unit->assertBelongsToItsWorld();
        });

        // Una unidad no cambia de evento ni deja de ser sucursal: si dejó de
        // operar se cierra por estado. Simétrico con tenant_id, que también
        // es inmutable.
        static::updating(function (OperatingUnit $unit): void {
            if ($unit->isDirty('event_id')) {
                throw InvalidOperatingUnitException::eventIsImmutable();
            }
        });
    }

    /**
     * Sucursal ⇒ cuenta de negocio. Punto de venta ⇒ cuenta de organizador y
     * evento de la misma cuenta.
     */
    protected function assertBelongsToItsWorld(): void
    {
        $tenant = Tenant::query()->find($this->tenant_id);

        if ($tenant === null) {
            return;
        }

        if ($this->event_id === null) {
            if (! $tenant->isBusiness()) {
                throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
            }

            return;
        }

        if (! $tenant->isOrganizer()) {
            throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
        }

        // Sin el scope de tenant a propósito: queremos saber de quién es el
        // evento de verdad, no si lo podemos ver.
        $eventTenantId = Event::query()->withoutTenancy()->whereKey($this->event_id)->value('tenant_id');

        if ($eventTenantId !== $this->tenant_id) {
            throw InvalidOperatingUnitException::eventOutsideTenant();
        }
    }

    /**
     * @param  QueryBuilder  $query
     * @return OperatingUnitBuilder<*>
     */
    public function newEloquentBuilder($query): OperatingUnitBuilder
    {
        return new OperatingUnitBuilder($query);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @param  Builder<OperatingUnit>  $query
     */
    public function scopeBranches(Builder $query): void
    {
        $query->whereNull('event_id');
    }

    /**
     * @param  Builder<OperatingUnit>  $query
     */
    public function scopeEventOutlets(Builder $query): void
    {
        $query->whereNotNull('event_id');
    }

    protected static function newFactory(): OperatingUnitFactory
    {
        return OperatingUnitFactory::new();
    }
}
