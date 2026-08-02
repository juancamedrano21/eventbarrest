<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Business\Models\BusinessAccount;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Queries\HomeForUser;
use App\Domains\Identity\Queries\UserPermissions;
use App\Domains\Platform\Enums\TenantStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de /business (ADR-007): la casa del bar o restaurante
 * independiente. Reconoce su mundo por lo que ES —una cuenta de negocio—
 * y no por descarte, y a quien no le corresponde lo devuelve a SU puerta
 * en lugar de darle un 403 sin salida.
 *
 * La entrada es POSITIVA por capacidades: se exige al menos un permiso de
 * gestión. Un rol sin permisos, o solo con los de la caja, queda fuera —
 * fail-closed, no «no parece cajero, que pase».
 */
class EnsureBusinessUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect('/entrar');
        }

        if (! $user->tenant instanceof BusinessAccount) {
            return redirect(app(HomeForUser::class)($user));
        }

        abort_if(
            $user->tenant->status === TenantStatus::Suspended,
            403,
            'La cuenta está suspendida.',
        );

        // Este mundo no tiene comercios dentro. Si un contexto de comercio se
        // colara —heredado de un job, de un runAs mal cerrado—, VendorScope
        // filtraría por un vendor_id que aquí siempre es nulo y el catálogo
        // entero del bar desaparecería sin un solo error. La puerta garantiza
        // la precondición en vez de confiar en que nadie la rompa.
        app(VendorContext::class)->clear();

        $permisos = app(UserPermissions::class)->namesFor($user);

        if ($permisos->intersect(Permission::businessManagement())->isEmpty()) {
            return $user->canOperateThePos()
                ? redirect('/pos')
                : abort(403, 'Tu rol no incluye la gestión del negocio.');
        }

        return $next($request);
    }
}
