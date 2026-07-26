<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use DateTimeInterface;

class CreateEvent
{
    public function __invoke(
        string $name,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?string $venue = null,
        EventStatus $status = EventStatus::Draft,
    ): Event {
        $tenant = app(TenantContext::class)->currentOrFail();

        if ($tenant->type !== TenantType::Organizer) {
            throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
        }

        return Event::create([
            'name' => $name,
            'venue' => $venue,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);
    }
}
