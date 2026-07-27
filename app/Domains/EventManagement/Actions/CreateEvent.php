<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use DateTimeInterface;

/**
 * Alta de un evento en la cuenta de organizador activa. El modelo Event es
 * quien rechaza cuentas que no sean de organizador.
 */
class CreateEvent
{
    public function __invoke(
        string $name,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?string $venue = null,
        EventStatus $status = EventStatus::Draft,
    ): Event {
        return Event::create([
            'name' => $name,
            'venue' => $venue,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);
    }
}
