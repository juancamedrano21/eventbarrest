<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Concerns;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\Scopes\VendorScope;
use App\Domains\EventManagement\VendorContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segundo nivel de pertenencia, sobre el de tenant: a qué negocio pertenece
 * la fila dentro de la cuenta.
 *
 * Asimétrico a propósito respecto de BelongsToTenant, porque los dos mundos
 * lo usan distinto:
 *
 * - Cuenta de negocio: no hay negocios internos. `vendor_id` queda nulo y
 *   el aislamiento por cuenta ya basta.
 * - Cuenta de organizador: cada fila pertenece a un negocio participante.
 *   Un usuario de negocio tiene su contexto fijado siempre y solo ve lo
 *   suyo; el equipo del organizador puede mirar el consolidado de su cuenta.
 *
 * Por eso el scope filtra cuando hay negocio activo, y no filtra cuando no
 * lo hay: la vista consolidada es legítima y el aislamiento fuerte —
 * el que impide ver otra cuenta — lo sigue dando TenantScope.
 */
trait BelongsToVendor
{
    public static function bootBelongsToVendor(): void
    {
        static::addGlobalScope(new VendorScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('vendor_id') !== null) {
                return;
            }

            $model->setAttribute('vendor_id', app(VendorContext::class)->id());
        });
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
