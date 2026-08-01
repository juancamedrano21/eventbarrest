<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Queries\UserPermissions;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Models\CashSession;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Todo lo que el POS necesita al arrancar: quién soy, en qué unidades puedo
 * trabajar (las de MI comercio, o las de la cuenta en el mundo de negocios)
 * y qué cajas están abiertas. El contexto ya viene fijado por el middleware.
 */
class PosBootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendors = app(VendorContext::class);

        $units = OperatingUnit::query()
            ->where('status', OperatingUnitStatus::Active->value)
            ->when($vendors->check(), fn ($query) => $query->where('vendor_id', $vendors->id()))
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'vendor_id']);

        $openSessions = CashSession::query()
            ->where('status', CashSessionStatus::Open->value)
            ->whereIn('operating_unit_id', $units->pluck('id'))
            ->get(['id', 'operating_unit_id', 'opening_cents', 'opened_at']);

        return response()->json([
            'user' => ['id' => $user?->id, 'name' => $user?->name],
            'permissions' => $user instanceof User
                ? app(UserPermissions::class)->namesFor($user)->values()
                : [],
            'units' => $units,
            'open_sessions' => $openSessions,
        ]);
    }
}
