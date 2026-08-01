<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Platform\Exceptions\CatalogInUseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de comida (dominicana, mexicana, mariscos...): catálogo de
 * PLATAFORMA, administrado por el superadmin.
 *
 * @property int $id
 * @property string $name
 */
class FoodType extends Model
{
    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::deleting(function (FoodType $type): void {
            if ($type->vendors()->withoutGlobalScopes()->exists()) {
                throw CatalogInUseException::foodType($type->name);
            }
        });
    }

    /** @return HasMany<Vendor, $this> */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }
}
