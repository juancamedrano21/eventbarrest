<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\VendorContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de CADA petición del POS, no solo del login: el portador debe
 * seguir pudiendo operar (rol vigente, cuenta y comercio activos) y su token
 * debe llevar la habilidad pos. Y en una cuenta de organizador el POS opera
 * SIEMPRE para un comercio: el equipo del organizador mira desde el panel,
 * no vende — sin comercio activo no hay POS.
 */
class EnsurePosCapability
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canOperateThePos(), 403, 'Este usuario no opera el punto de venta.');
        abort_unless($user->tokenCan('pos'), 403, 'El token no es de un dispositivo POS.');

        if ($user->tenant instanceof OrganizerAccount && ! app(VendorContext::class)->check()) {
            abort(403, 'El POS opera para un comercio: este usuario no pertenece a ninguno.');
        }

        return $next($request);
    }
}
