<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Concerns;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\Scopes\VendorScope;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Tenancy\TenantContext;
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
            $context = app(VendorContext::class);
            $given = $model->getAttribute('vendor_id');

            if ($given === null) {
                $model->setAttribute('vendor_id', $context->id());

                return;
            }

            // Con comercio activo nadie escribe a nombre de otro.
            if ($context->check() && (int) $given !== $context->id()) {
                throw VendorException::writingForAnotherVendor();
            }

            // Y el comercio explícito debe existir en la cuenta de la fila.
            // Sin scopes: este guard decide con la verdad, no con la vista.
            $vendorTenant = Vendor::query()->withoutGlobalScopes()
                ->whereKey($given)
                ->value('tenant_id');
            $rowTenant = $model->getAttribute('tenant_id') ?? app(TenantContext::class)->id();

            if ($vendorTenant === null || (int) $vendorTenant !== (int) $rowTenant) {
                throw VendorException::vendorOutsideTenant();
            }
        });

        // Una fila no cambia de comercio: su stock, sus recetas y sus ventas
        // dependen de a quién pertenece.
        static::updating(function (Model $model): void {
            if ($model->isDirty('vendor_id')) {
                throw VendorException::vendorIsImmutable();
            }
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
