<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Queries\UserPermissions;
use App\Domains\Platform\Enums\TenantStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de /comercio (ADR-007): el panel privado del personal del
 * comercio. Cada quien rebota a SU puerta — usuarios de cuenta a /panel,
 * gente sin capacidades de gestión a /pos si puede operarlo — y las
 * suspensiones cortan el paso igual que en todas partes.
 *
 * La entrada es POSITIVA por capacidades: se exige al menos un permiso de
 * gestión. Un rol sin permisos, o con permisos irrelevantes, queda fuera —
 * fail-closed, no «no parece cajero, que pase».
 */
class EnsureEventVendorUser
{
    /** Lo que convierte a alguien en gestor de su comercio. */
    private const GESTION = [
        Permission::CatalogManage,
        Permission::InventoryManage,
        Permission::InventoryTransfer,
        Permission::InventoryAdjust,
        Permission::ReportsViewUnit,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->worksForAVendor()) {
            return redirect('/event-panel');
        }

        abort_if(
            $user->tenant === null || $user->tenant->status === TenantStatus::Suspended,
            403,
            'La cuenta está suspendida.',
        );

        // Suspendido corta; «en alta» (draft) opera — igual que el POS y el
        // resolver de contexto: tres puertas, UNA misma regla.
        abort_if(
            $user->vendor === null || $user->vendor->status === VendorStatus::Suspended,
            403,
            'El comercio de este usuario no está disponible.',
        );

        $permisos = app(UserPermissions::class)->namesFor($user);
        $gestiona = $permisos->intersect(array_map(
            fn (Permission $permiso): string => $permiso->value,
            self::GESTION,
        ))->isNotEmpty();

        if (! $gestiona) {
            // La caja de SU mundo: /pos rechaza por modalidad al cajero de
            // un comercio de evento y lo dejaría sin ninguna puerta.
            return $user->canOperateThePos()
                ? redirect('/event-pos')
                : abort(403, 'Tu rol no incluye la gestión del comercio.');
        }

        return $next($request);
    }
}
