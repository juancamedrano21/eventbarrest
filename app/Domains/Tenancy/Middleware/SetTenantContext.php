<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant de la petición a partir del usuario autenticado, tanto para
 * el aislamiento de datos (TenantContext) como para los roles de
 * spatie/permission (que en modo teams necesita saber el equipo activo).
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
        }

        return $next($request);
    }
}
