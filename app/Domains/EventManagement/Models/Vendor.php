<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un negocio participante: el bar o restaurante que vende dentro de los
 * eventos de un organizador. Maneja su catálogo, su inventario, su equipo y
 * sus ventas por separado; el organizador lo relaciona con sus eventos y ve
 * el consolidado, pero no opera por él.
 *
 * Solo existe en cuentas de organizador: un bar independiente de la
 * plataforma es una cuenta propia, no un negocio de evento (doc 01).
 *
 * @property int $id
 * @property string $name
 * @property string|null $rnc
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property VendorStatus $status
 */
class Vendor extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'rnc',
        'contact_name',
        'contact_phone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Los negocios de evento solo existen en el mundo de los
        // organizadores; en una cuenta de negocio no tienen sentido.
        static::creating(function (Vendor $vendor): void {
            $tenant = Tenant::query()->find($vendor->tenant_id);

            if ($tenant !== null && ! $tenant instanceof OrganizerAccount) {
                throw VendorException::onlyInOrganizerAccounts();
            }
        });
    }

    /**
     * Los eventos en los que participa. La comisión vive en la
     * participación, no en el negocio: puede variar por evento.
     *
     * @return BelongsToMany<Event, $this>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_vendor')
            ->withPivot(['id', 'tenant_id', 'commission_bps'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OperatingUnit, $this>
     */
    public function operatingUnits(): HasMany
    {
        return $this->hasMany(OperatingUnit::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isActive(): bool
    {
        return $this->status === VendorStatus::Active;
    }

    protected static function newFactory(): VendorFactory
    {
        return VendorFactory::new();
    }
}
