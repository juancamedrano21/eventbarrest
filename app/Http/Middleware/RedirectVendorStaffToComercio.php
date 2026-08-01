<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El rebote del ADR-007 en el Filament de cuentas: el personal de comercio
 * que aterrice en el DASHBOARD de /app tras el login va a SU puerta
 * (/comercio) en vez de a un panel que le oculta todo. Solo el dashboard:
 * el resto de /app les queda accesible mientras dure la migración.
 */
class RedirectVendorStaffToComercio
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->worksForAVendor()
            && $request->routeIs('filament.app.pages.dashboard')) {
            return redirect('/comercio');
        }

        return $next($request);
    }
}
