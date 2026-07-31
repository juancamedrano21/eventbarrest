<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el contexto de la petición a partir del usuario autenticado: el
 * tenant, tanto para el aislamiento de datos (TenantContext) como para los
 * roles de spatie/permission (que en modo teams necesita saber el equipo
 * activo), y el comercio (VendorContext) cuando el usuario es personal de
 * uno — ese usuario opera SIEMPRE dentro de su comercio.
 *
 * Sin este middleware el panel del negocio no vería ningún dato: TenantScope
 * falla cerrado a propósito.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        if ($tenant !== null && $tenant->status !== TenantStatus::Suspended) {
            app(TenantContext::class)->set($tenant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            // La relación roles() se filtra por el equipo VIGENTE cuando se
            // carga. Si algo la tocó antes de esta línea (autenticación,
            // un observer, una vista), quedó cacheada con equipo nulo — es
            // decir, vacía — y el usuario perdería todos sus permisos
            // durante el resto de la petición. Se descarta para que se
            // vuelva a cargar ya con el equipo correcto.
            // Dentro de este if el usuario existe: sin él no habría tenant.
            $user->unsetRelation('roles')->unsetRelation('permissions');

            // Se limpia antes de fijar: fuera de Octane el contenedor puede
            // conservar el contexto de una petición anterior (tests, colas).
            $vendors = app(VendorContext::class);
            $vendors->clear();

            if ($user->vendor_id !== null) {
                // Con el tenant ya fijado, el scope de Vendor garantiza que
                // solo se encuentre un comercio de ESTA cuenta.
                $vendor = Vendor::query()->find($user->vendor_id);

                if ($vendor !== null) {
                    $vendors->set($vendor);
                }
            }
        }

        return $next($request);
    }
}
