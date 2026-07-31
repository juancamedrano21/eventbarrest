<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\Tenancy\ContextResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el contexto de la petición a partir del usuario autenticado: la
 * cuenta (TenantContext + equipo de spatie/permission) y el comercio
 * (VendorContext) cuando el usuario es personal de uno — ese usuario opera
 * SIEMPRE dentro de su comercio. La lógica vive en ContextResolver,
 * compartida con el helper de tests.
 *
 * Sin este middleware el panel del negocio no vería ningún dato: TenantScope
 * falla cerrado a propósito.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        app(ContextResolver::class)->forUser($user instanceof User ? $user : null);

        return $next($request);
    }
}
