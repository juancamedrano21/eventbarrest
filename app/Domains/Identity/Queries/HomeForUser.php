<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Domains\Business\Models\BusinessAccount;
use App\Domains\Identity\Enums\Permission;
use App\Models\User;

/**
 * La puerta de cada quien (ADR-007). Una sola pieza decide a dónde va un
 * usuario tras entrar, para que el login, los rebotes y los enlaces no
 * puedan contradecirse entre sí.
 *
 * El orden importa: primero la plataforma, después el MUNDO de la cuenta y
 * solo dentro de cada mundo la pregunta de gestión-o-caja. Cada mundo se
 * reconoce por lo que ES (instanceof), nunca por descarte: así una tercera
 * modalidad futura no hereda por accidente la puerta de otra.
 */
class HomeForUser
{
    public function __invoke(User $user): string
    {
        if ($user->isPlatformStaff()) {
            return '/saas-admin';
        }

        $permisos = app(UserPermissions::class)->namesFor($user);

        // El bar independiente: una sola casa, sin comercios dentro.
        if ($user->tenant instanceof BusinessAccount) {
            return $permisos->intersect(Permission::businessManagement())->isNotEmpty()
                ? '/business'
                : ($user->canOperateThePos() ? '/pos' : '/business');
        }

        if (! $user->worksForAVendor()) {
            return '/event-panel';
        }

        if ($permisos->intersect(Permission::vendorManagement())->isNotEmpty()) {
            return '/event-vendor';
        }

        // Su trabajo entero ocurre en la caja, y la caja de este mundo es la
        // de eventos: /pos rechazaría a este cajero por modalidad.
        return $user->canOperateThePos() ? '/event-pos' : '/event-vendor';
    }
}
