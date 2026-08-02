<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business\Concerns;

use App\Domains\Business\Models\BusinessAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La autorización por pantalla de /business: el middleware ya garantizó que
 * es una cuenta de negocio activa; aquí se exige el permiso del caso (null =
 * basta con la puerta, como en el resumen) y se resuelve SU cuenta — nunca
 * una elegida por URL.
 */
trait AuthorizesBusinessPanel
{
    protected function negocioDe(Request $request, ?string $permission = null): BusinessAccount
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->tenant instanceof BusinessAccount
            && ($permission === null || $user->can($permission)),
            403,
        );

        return $user->tenant;
    }

    /**
     * Quién hace el cambio. Las Actions que tocan roles lo piden para aplicar
     * el techo antiescalada: nadie concede lo que no tiene.
     */
    protected function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
