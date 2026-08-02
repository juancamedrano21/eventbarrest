<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Business\Models\BusinessAccount;
use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\Platform\Eloquent\TenantBuilder;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Exceptions\TenantBaseIsNotCreatableException;
use App\Domains\Platform\Exceptions\TenantTypeIsImmutableException;
use App\Domains\Sales\Enums\ItbisMode;
use App\Models\User;
use App\Support\Eloquent\HasChildModels;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * La vista de plataforma de una cuenta: alta, plan, suspensión, equipo.
 * Neutral respecto a los mundos — cada uno tiene su propia clase
 * (BusinessAccount, OrganizerAccount) y las filas se hidratan como la que
 * les corresponde. El super admin administra cuentas; los mundos operan.
 *
 * @property int $id
 * @property string $name
 * @property string|null $rnc
 * @property TenantType $type
 * @property TenantStatus $status
 * @property ItbisMode $itbis_mode regla fiscal por defecto de la cuenta
 */
class Tenant extends Model
{
    use HasChildModels;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use LogsActivity;

    protected $table = 'tenants';

    // 'type' queda fuera a propósito: define el mundo de la cuenta y solo lo
    // fijan las clases hijas al nacer.
    protected $fillable = [
        'name',
        'rnc',
        'status',
        'itbis_mode',
    ];

    public static function childTypes(): array
    {
        return [
            TenantType::Business->value => BusinessAccount::class,
            TenantType::Organizer->value => OrganizerAccount::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'itbis_mode' => ItbisMode::class,
        ];
    }

    protected static function booted(): void
    {
        // La base es una vista, no un mundo: las cuentas nacen como
        // BusinessAccount u OrganizerAccount, nunca aquí.
        static::creating(function (Tenant $tenant): void {
            if ($tenant::class === self::class) {
                throw TenantBaseIsNotCreatableException::make();
            }
        });

        static::updating(function (Tenant $tenant): void {
            if ($tenant->isDirty('type')) {
                throw TenantTypeIsImmutableException::forTenant($tenant->name);
            }
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return TenantBuilder<*>
     */
    public function newEloquentBuilder($query): TenantBuilder
    {
        return new TenantBuilder($query);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isOrganizer(): bool
    {
        return false;
    }

    public function isBusiness(): bool
    {
        return false;
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
