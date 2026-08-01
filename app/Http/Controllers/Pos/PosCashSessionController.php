<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Actions\CloseCashSession;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Models\CashSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Apertura y cierre de caja desde el POS. La unidad se resuelve con los
 * scopes activos (una ajena no existe) y los guards del dominio deciden el
 * resto: una sola caja abierta, coherencia de comercio, cierre sin órdenes
 * pendientes.
 */
class PosCashSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::CashSessionManage->value) === true, 403);

        $data = $request->validate([
            'operating_unit_id' => ['required', 'integer'],
            'opening_cents' => ['required', 'integer', 'min:0'],
        ]);

        $unit = OperatingUnit::query()->findOrFail($data['operating_unit_id']);
        $session = app(OpenCashSession::class)($unit, $request->user(), $data['opening_cents']);

        return response()->json([
            'id' => $session->id,
            'operating_unit_id' => $session->operating_unit_id,
            'opening_cents' => $session->opening_cents,
            'opened_at' => $session->opened_at,
        ], 201);
    }

    public function close(Request $request, CashSession $cashSession): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::CashSessionManage->value) === true, 403);

        $data = $request->validate([
            'counted_cents' => ['required', 'integer', 'min:0'],
        ]);

        $session = app(CloseCashSession::class)($cashSession, $data['counted_cents']);

        return response()->json([
            'id' => $session->id,
            'expected_cents' => $session->expected_cents,
            'closing_cents' => $session->closing_cents,
            'difference_cents' => $session->difference_cents,
            'closed_at' => $session->closed_at,
        ]);
    }
}
