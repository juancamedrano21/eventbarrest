<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\TenantContext;
use App\Support\Eloquent\IsChildModel;
use Database\Factories\EventOutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de venta (barra o cocina) dentro de un evento: la unidad operativa
 * del mundo de los organizadores. Nace dentro de un evento y muere con él —
 * no existe suelto.
 *
 * Aquí es donde entra un negocio que quiera participar en un festival: como
 * punto del evento, con su propio catálogo, inventario y personal, sin
 * relación alguna con su negocio de la plataforma.
 *
 * @property int|null $event_id Nulo solo antes de guardar: el hook creating lo exige
 * @property-read Event $event
 */
class EventOutlet extends OperatingUnit
{
    /** @use HasFactory<EventOutletFactory> */
    use HasFactory;

    use IsChildModel;

    public static function childTypeValue(): string
    {
        return OperatingUnitType::EventOutlet->value;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (EventOutlet $outlet): void {
            if ($outlet->event_id === null) {
                throw InvalidOperatingUnitException::outletNeedsAnEvent();
            }

            $tenant = app(TenantContext::class)->current();

            if ($tenant !== null && ! $tenant instanceof OrganizerAccount) {
                throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
            }

            // Sin el scope de tenant a propósito: queremos saber de quién es
            // el evento de verdad, no si lo podemos ver.
            $eventTenantId = Event::query()->withoutTenancy()->whereKey($outlet->event_id)->value('tenant_id');

            if ($eventTenantId !== $outlet->tenant_id) {
                throw InvalidOperatingUnitException::eventOutsideTenant();
            }
        });
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected static function newFactory(): EventOutletFactory
    {
        return EventOutletFactory::new();
    }
}
