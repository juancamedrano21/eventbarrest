<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use DateTimeInterface;

/**
 * Cambios sobre un evento existente, incluido su ESTADO — sin esto, un
 * festival no se puede cerrar ni liquidar desde ninguna pantalla.
 *
 * Solo se escribe lo que llega: un formulario parcial no puede borrar en
 * silencio lo que no muestra. Un evento no se borra nunca: sus ventas, sus
 * comercios y sus liquidaciones lo referencian para siempre.
 */
class UpdateEvent
{
    public function __invoke(
        Event $event,
        ?string $name = null,
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $endsAt = null,
        ?string $venue = null,
        ?EventStatus $status = null,
    ): Event {
        // Por fill() y no por asignación directa, igual que CreateEvent: así
        // las fechas pasan por el cast del modelo en vez de chocar con el
        // tipo de la propiedad.
        $attrs = [];

        if ($name !== null) {
            $attrs['name'] = $name;
        }

        if ($startsAt !== null) {
            $attrs['starts_at'] = $startsAt;
        }

        if ($endsAt !== null) {
            $attrs['ends_at'] = $endsAt;
        }

        if (func_num_args() >= 5) {
            // Presente aunque venga vacío significa «sin lugar».
            $attrs['venue'] = $venue;
        }

        if ($status !== null) {
            $attrs['status'] = $status;
        }

        if ($attrs !== []) {
            $event->fill($attrs)->save();
        }

        return $event;
    }
}
