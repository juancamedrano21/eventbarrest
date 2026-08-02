<?php

declare(strict_types=1);

namespace App\Domains\Sales\Queries;

use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Sales\Exceptions\SalesException;
use Illuminate\Support\Facades\DB;

/**
 * La regla fiscal vigente: la del comercio si la declaró, si no la de su
 * cuenta. Un comercio de evento es un negocio tercero y puede vender con
 * el impuesto por fuera aunque el organizador lo incluya (y al revés).
 *
 * Lecturas por el query builder CRUDO a propósito: sin los casts de
 * Eloquent, un valor corrupto en la columna (import, seeder, SQL manual)
 * se convierte en un error de dominio explicable en vez de un ValueError
 * que tumba el catálogo del POS con un 500. Y el comercio se acota por
 * tenant: nadie hereda la regla fiscal de otra cuenta.
 */
class ResolveItbisMode
{
    public function forVendor(?int $vendorId, int $tenantId): ItbisMode
    {
        if ($vendorId !== null) {
            $delComercio = DB::table('vendors')
                ->where('id', $vendorId)
                ->where('tenant_id', $tenantId)
                ->value('itbis_mode');

            if (filled($delComercio)) {
                return $this->parse((string) $delComercio, 'del comercio');
            }
        }

        return $this->forTenant($tenantId);
    }

    public function forTenant(int $tenantId): ItbisMode
    {
        $modo = DB::table('tenants')->where('id', $tenantId)->value('itbis_mode');

        // Sin fila o sin valor: la regla declarada del producto (así se
        // vende en la mayoría de los bares de RD).
        return filled($modo)
            ? $this->parse((string) $modo, 'de la cuenta')
            : ItbisMode::Included;
    }

    private function parse(string $modo, string $origen): ItbisMode
    {
        return ItbisMode::tryFrom($modo)
            ?? throw SalesException::unknownItbisMode($modo, $origen);
    }
}
