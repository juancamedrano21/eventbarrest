<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventVendor\Concerns;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * La autorización por pantalla de /comercio: el middleware ya garantizó que
 * es personal de comercio activo; aquí se exige el permiso del caso (null =
 * basta con la puerta, como en el home) y se resuelve SU comercio — nunca
 * uno elegido por URL.
 */
trait AuthorizesEventVendorPanel
{
    protected function comercioDe(Request $request, ?Permission $permission = null): Vendor
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && ($permission === null || $user->can($permission->value)),
            403,
        );

        return Vendor::query()->findOrFail($user->vendor_id);
    }
}
