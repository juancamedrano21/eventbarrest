<?php

declare(strict_types=1);

namespace App\Domains\Operations\Actions;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\TenantContext;

/**
 * Alta de un punto de venta (barra o cocina) dentro de un evento.
 *
 * Aquí es donde entra un negocio que quiera participar en un festival: se crea
 * como punto del evento, con su propio catálogo, inventario y personal. Aunque
 * lleve el mismo nombre que un negocio cliente, no comparte nada con él.
 */
class CreateEventOutlet
{
    public function __invoke(
        Event $event,
        string $name,
        OperatingUnitKind $kind,
        OperatingUnitStatus $status = OperatingUnitStatus::Active,
    ): OperatingUnit {
        $tenant = app(TenantContext::class)->currentOrFail();

        // El evento se resuelve bajo el scope del tenant activo, pero lo
        // comprobamos igual: recibir un modelo ya cargado saltaría el scope.
        if ($event->tenant_id !== $tenant->id) {
            throw InvalidOperatingUnitException::eventOutsideTenant();
        }

        $unit = new OperatingUnit([
            'name' => $name,
            'kind' => $kind,
            'status' => $status,
        ]);

        $unit->event_id = $event->id;
        $unit->save();

        return $unit;
    }
}
