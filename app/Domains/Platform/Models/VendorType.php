<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Platform\Exceptions\CatalogInUseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de negocio (Bar, Restaurante, Otros...): catálogo de PLATAFORMA,
 * administrado por el superadmin y compartido por todas las cuentas.
 *
 * @property int $id
 * @property string $name
 */
class VendorType extends Model
{
    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::deleting(function (VendorType $type): void {
            if ($type->vendors()->withoutGlobalScopes()->exists()) {
                throw CatalogInUseException::vendorType($type->name);
            }
        });
    }

    /** @return HasMany<Vendor, $this> */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }
}
