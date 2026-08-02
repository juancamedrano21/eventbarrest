<?php

declare(strict_types=1);

namespace App\Domains\Sales\Queries;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Sales\Enums\ItbisMode;

/**
 * La regla fiscal vigente: la del comercio si la declaró, si no la de su
 * cuenta. Un comercio de evento es un negocio tercero y puede vender con
 * el impuesto por fuera aunque el organizador lo incluya (y al revés).
 *
 * Consultas sin scopes a propósito: esto resuelve la verdad de las filas,
 * y se llama desde el POS donde el contexto ya está fijado pero la venta
 * puede pertenecer a otra unidad.
 */
class ResolveItbisMode
{
    public function forVendor(?int $vendorId, int $tenantId): ItbisMode
    {
        if ($vendorId !== null) {
            $delComercio = $this->normalize(
                Vendor::query()->withoutGlobalScopes()->whereKey($vendorId)->value('itbis_mode')
            );

            if ($delComercio !== null) {
                return $delComercio;
            }
        }

        return $this->forTenant($tenantId);
    }

    public function forTenant(int $tenantId): ItbisMode
    {
        return $this->normalize(
            Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->value('itbis_mode')
        ) ?? ItbisMode::Included;
    }

    /**
     * value() aplica los casts del modelo, así que puede devolver el enum
     * ya hidratado o el string crudo según por dónde entre.
     */
    private function normalize(mixed $modo): ?ItbisMode
    {
        return match (true) {
            $modo instanceof ItbisMode => $modo,
            is_string($modo) && $modo !== '' => ItbisMode::tryFrom($modo),
            default => null,
        };
    }
}
