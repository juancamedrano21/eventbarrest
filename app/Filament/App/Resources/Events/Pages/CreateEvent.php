<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\Pages;

use App\Domains\EventManagement\Actions\CreateEvent as CreateEventAction;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Filament\App\Resources\Events\EventResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateEventAction::class)(
            $data['name'],
            CarbonImmutable::parse($data['starts_at']),
            CarbonImmutable::parse($data['ends_at']),
            $data['venue'] ?? null,
            EventStatus::coerce($data['status']),
        );
    }
}
