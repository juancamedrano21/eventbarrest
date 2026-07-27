<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Tenancy\TenantContext;
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
        // Un festival solo existe en el mundo de los organizadores. La regla
        // vive aquí y no en una acción, porque cualquier seeder o job futuro
        // escribe por el modelo.
        static::creating(function (Event $event): void {
            $tenant = app(TenantContext::class)->current();

            if ($tenant !== null && ! $tenant instanceof OrganizerAccount) {
                throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
            }
        });
    }

    /**
     * @return HasMany<EventOutlet, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(EventOutlet::class, 'event_id');
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
