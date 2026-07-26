<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un festival, feria o temporada de una cuenta de organizador. Es un mundo
 * cerrado: sus puntos de venta tienen su propio catálogo, inventario y
 * personal, sin relación con ningún negocio de la plataforma.
 *
 * @property int $id
 * @property string $name
 * @property string|null $venue
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property EventStatus $status
 */
class Event extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'venue',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Un festival solo existe dentro de una cuenta de organizador. La
        // regla vive aquí y no solo en la acción, porque cualquier seeder o
        // job futuro escribe por el modelo.
        static::creating(function (Event $event): void {
            $tenant = Tenant::query()->find($event->tenant_id);

            if ($tenant !== null && ! $tenant->isOrganizer()) {
                throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
            }
        });
    }

    /**
     * @return HasMany<OperatingUnit, $this>
     */
    public function operatingUnits(): HasMany
    {
        return $this->hasMany(OperatingUnit::class);
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
