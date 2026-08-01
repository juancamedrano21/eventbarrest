<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Concerns;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\Identity\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * La frontera única del panel nuevo para pantallas de organizador: equipo de
 * la CUENTA con el permiso del caso — jamás personal de comercio. Todo
 * controlador del panel autoriza por aquí (disciplina del ADR-006).
 */
trait AuthorizesOrganizerPanel
{
    protected function authorizeOrganizer(Request $request, Permission $permission): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && ! $user->worksForAVendor()
            && $user->tenant instanceof OrganizerAccount
            && $user->can($permission->value),
            403,
        );
    }
}
