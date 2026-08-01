<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\RoleTemplateException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Identity\Queries\UserPermissions;
use App\Models\User;

/**
 * Nadie asigna por encima de su propio techo: un rol solo se concede si sus
 * permisos son subconjunto de los permisos efectivos de quien lo concede.
 * Cierra la escalada clásica — el que solo tiene users.manage ya no puede
 * ascenderse (ni ascender a un tercero) a dueño.
 *
 * El techo se mide por CAPACIDAD, no por nombre de rol: así resiste también
 * los roles que cree el superadmin. El staff de plataforma y los procesos
 * sin actor (seeders, comandos, tests de dominio) no tienen techo.
 */
class GrantCeiling
{
    public static function assert(RoleTemplate $template, ?User $actor = null): void
    {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        if ($actor === null || $actor->isPlatformStaff()) {
            return;
        }

        $ceiling = app(UserPermissions::class)->namesFor($actor);

        if (collect($template->permissions)->diff($ceiling)->isNotEmpty()) {
            throw RoleTemplateException::cannotGrantBeyondSelf($template->label);
        }
    }
}
