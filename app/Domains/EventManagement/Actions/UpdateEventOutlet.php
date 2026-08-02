<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;

/**
 * Cambios sobre un puesto de evento: cómo se llama, qué despacha y si sigue
 * operando. Su evento y su comercio no se tocan — mover un puesto de dueño
 * reescribiría a quién pertenecen las ventas que ya salieron por él.
 */
class UpdateEventOutlet
{
    public function __invoke(
        EventOutlet $outlet,
        ?string $name = null,
        ?OperatingUnitKind $kind = null,
        ?OperatingUnitStatus $status = null,
    ): EventOutlet {
        if ($name !== null) {
            $outlet->name = $name;
        }

        if ($kind !== null) {
            $outlet->kind = $kind;
        }

        if ($status !== null) {
            $outlet->status = $status;
        }

        $outlet->save();

        return $outlet;
    }
}
