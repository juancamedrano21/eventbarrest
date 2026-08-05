<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\EventApp\Support\CacheDeRespuesta;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Models\FoodType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Platform\Models\VendorType;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * `kds_blind_attempts` y `kds_blind_pause_until` viven aquí y no en sus
 * puestos porque un intento de alta de tablet fallido no identifica ningún
 * puesto —el índice ciego se deriva del propio PIN— y sí identifica el
 * comercio: trae su código. NO deciden quién entra ni bloquean nada; lo único
 * que hacen es dejar de comprar CPU para contestar que no. El porqué está en
 * EnrollKdsDevice::anotarFallo.
 *
 * @property int $id
 * @property string $name
 * @property string|null $rnc
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property VendorStatus $status
 * @property string|null $logo_path
 * @property int|null $vendor_type_id
 * @property int|null $food_type_id
 * @property ItbisMode|null $itbis_mode
 * @property int $kds_blind_attempts
 * @property string|null $kds_blind_pause_until
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
        'logo_path',
        'vendor_type_id',
        'food_type_id',
        'itbis_mode',
    ];

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            // Null a propósito: hereda la regla fiscal de su cuenta.
            'itbis_mode' => ItbisMode::class,
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

        // La base de datos ya lo restringe (restrictOnDelete); este guard
        // convierte el error críptico de FK en un mensaje operable.
        static::deleting(function (Vendor $vendor): void {
            if ($vendor->users()->exists()) {
                throw VendorException::hasUsers($vendor->name);
            }
        });

        // Suspender un comercio tira la lista cacheada de TODOS sus eventos.
        //
        // Sin esto, la recuperación que la app construyó para el comercio
        // suspendido no funciona: el asistente tiene la carta abierta, recibe
        // el 404, la app lo manda de vuelta a la lista para que vea la verdad
        // —y la lista vuelve del caché con el puesto todavía puesto, así que
        // vuelve a entrar y vuelve a chocar—. Un bucle de hasta diez segundos
        // justo en el momento en que la pantalla tenía que explicarse.
        //
        // Se engancha SOLO al cambio de estado y no a cualquier escritura del
        // comercio: es una operación rara, del organizador, y no está en
        // ningún camino caliente. El catálogo sigue sin engancharse a
        // propósito —eso sí son escrituras de cada venta— y para eso está el
        // TTL corto.
        static::updated(function (Vendor $vendor): void {
            if (! $vendor->wasChanged('status')) {
                return;
            }

            $vendor->events()->pluck('events.id')
                ->each(fn (int $evento) => CacheDeRespuesta::olvidar(CacheDeRespuesta::COMERCIOS, $evento));
        });
    }

    /**
     * Su gente: los usuarios que operan únicamente dentro de este comercio.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    /** @return BelongsTo<FoodType, $this> */
    public function foodType(): BelongsTo
    {
        return $this->belongsTo(FoodType::class);
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
