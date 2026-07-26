<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Exceptions\TenantTypeIsImmutableException;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string|null $rnc
 * @property TenantType $type
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use LogsActivity;

    // 'type' queda fuera a propósito: define el mundo de la cuenta y toda su
    // estructura operativa. Se fija solo al crear, desde CreateTenant.
    protected $fillable = [
        'name',
        'rnc',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (Tenant $tenant): void {
            if ($tenant->isDirty('type')) {
                throw TenantTypeIsImmutableException::forTenant($tenant->name);
            }
        });
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<OperatingUnit, $this>
     */
    public function operatingUnits(): HasMany
    {
        return $this->hasMany(OperatingUnit::class);
    }

    public function isOrganizer(): bool
    {
        return $this->type === TenantType::Organizer;
    }

    public function isBusiness(): bool
    {
        return $this->type === TenantType::Business;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('platform');
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
