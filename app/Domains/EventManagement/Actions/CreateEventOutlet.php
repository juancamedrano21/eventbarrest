<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;

/**
 * Alta de un punto de venta (barra o cocina) que un negocio atiende dentro
 * de un evento. El modelo EventOutlet garantiza que el evento y el negocio
 * sean de la cuenta activa, que esta sea de organizador, y que el negocio
 * participe de verdad en ese evento.
 */
class CreateEventOutlet
{
    public function __invoke(
        Event $event,
        Vendor $vendor,
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
        $outlet->vendor_id = $vendor->id;
        $outlet->save();

        return $outlet;
    }
}
