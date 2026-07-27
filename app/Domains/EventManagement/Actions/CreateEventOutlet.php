<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;

/**
 * Alta de un punto de venta (barra o cocina) dentro de un evento. El modelo
 * EventOutlet es quien garantiza que el evento exista, sea de la cuenta
 * activa y que esta sea de organizador.
 */
class CreateEventOutlet
{
    public function __invoke(
        Event $event,
        string $name,
        OperatingUnitKind $kind,
        OperatingUnitStatus $status = OperatingUnitStatus::Active,
    ): EventOutlet {
        $outlet = new EventOutlet([
            'name' => $name,
            'kind' => $kind,
            'status' => $status,
        ]);

        $outlet->event_id = $event->id;
        $outlet->save();

        return $outlet;
    }
}
