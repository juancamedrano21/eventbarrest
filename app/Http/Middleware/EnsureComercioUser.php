<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\Platform\Enums\TenantStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de /comercio (ADR-007): el panel privado del personal del
 * comercio. Cada quien rebota a SU puerta — usuarios de cuenta a /panel,
 * gente cuyo trabajo entero es el POS a /pos — y las suspensiones cortan
 * el paso igual que en todas partes.
 */
class EnsureComercioUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->worksForAVendor()) {
            return redirect('/panel');
        }

        if ($user->onlyOperatesThePos()) {
            return redirect('/pos');
        }

        abort_if($user->tenant === null || $user->tenant->status === TenantStatus::Suspended, 403);
        abort_if($user->vendor?->status !== VendorStatus::Active, 403);

        return $next($request);
    }
}
