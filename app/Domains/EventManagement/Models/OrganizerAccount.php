<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Support\Eloquent\IsChildModel;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta del mundo de los eventos: la productora que monta festivales. Su
 * estructura son eventos, y dentro de cada uno sus puntos de venta — esta
 * clase no sabe lo que es una sucursal.
 */
class OrganizerAccount extends Tenant
{
    use IsChildModel;

    public static function childTypeValue(): string
    {
        return TenantType::Organizer->value;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new()->organizer();
    }

    public function isOrganizer(): bool
    {
        return true;
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'tenant_id');
    }
}
