<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Domains\Identity\Enums\Permission;
use App\Models\User;

/**
 * La puerta de cada quien (ADR-007). Una sola pieza decide a dónde va un
 * usuario tras entrar, para que el login, los rebotes y los enlaces no
 * puedan contradecirse entre sí.
 */
class HomeForUser
{
    /** Los permisos que hacen a alguien gestor de su comercio. */
    private const GESTION = [
        Permission::CatalogManage,
        Permission::InventoryManage,
        Permission::InventoryTransfer,
        Permission::InventoryAdjust,
        Permission::ReportsViewUnit,
    ];

    public function __invoke(User $user): string
    {
        if ($user->isPlatformStaff()) {
            return '/saas-admin';
        }

        if (! $user->worksForAVendor()) {
            return '/event-panel';
        }

        $permisos = app(UserPermissions::class)->namesFor($user);

        $gestiona = $permisos->intersect(array_map(
            fn (Permission $permiso): string => $permiso->value,
            self::GESTION,
        ))->isNotEmpty();

        if ($gestiona) {
            return '/event-vendor';
        }

        // Su trabajo entero ocurre en la caja.
        return $user->canOperateThePos() ? '/pos' : '/event-vendor';
    }
}
