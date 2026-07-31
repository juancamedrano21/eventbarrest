<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Platform\Enums\TenantStatus;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * La única pieza que decide el contexto de un usuario autenticado: cuenta,
 * equipo de permisos y comercio. La consumen el middleware y el helper de
 * tests por igual, para que no puedan divergir.
 *
 * Empieza siempre limpiando: fuera de Octane el contenedor puede conservar
 * el contexto de una petición anterior (tests, colas), y heredarlo sería
 * operar como otra cuenta.
 */
class ContextResolver
{
    public function forUser(?User $user): void
    {
        app(TenantContext::class)->clear();
        app(VendorContext::class)->clear();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        app(TenantContext::class)->set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // La relación roles() se filtra por el equipo VIGENTE cuando se
        // carga. Si algo la tocó antes (autenticación, un observer), quedó
        // cacheada con equipo nulo — vacía — y el usuario perdería sus
        // permisos el resto de la petición. Se descarta para recargarla ya
        // con el equipo correcto.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        if ($user->vendor_id === null) {
            return;
        }

        // Con el tenant fijado, el scope de Vendor garantiza que solo se
        // encuentre un comercio de ESTA cuenta. Si no aparece o está
        // suspendido, se niega la petición: continuar sin comercio activo
        // fallaría ABIERTO — el usuario vería el consolidado de la cuenta.
        $vendor = Vendor::query()->find($user->vendor_id);

        if ($vendor === null || $vendor->status === VendorStatus::Suspended) {
            abort(403, 'El comercio de este usuario no está disponible.');
        }

        app(VendorContext::class)->set($vendor);
    }
}
