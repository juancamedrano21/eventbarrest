<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventApp\Actions\IssueEventPublicCode;
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
        $event = Event::create([
            'name' => $name,
            'venue' => $venue,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);

        // Nace con su código público, igual que un comercio nace con el de su
        // tablet. Se emite aquí y no la primera vez que alguien abre la app
        // para que ningún evento pueda existir sin él: el código es lo que se
        // compila dentro del binario que se sube a la tienda, y eso se
        // prepara semanas antes de que el festival abra.
        app(IssueEventPublicCode::class)($event);

        return $event;
    }
}
