<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Business\Actions\UpdateBranch;
use App\Domains\Business\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Models\CashSession;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Las sucursales del negocio: la estructura sobre la que cuelgan las cajas,
 * el stock y las ventas.
 *
 * Solo dos estados se ofrecen, Activa y Cerrada. El enum tiene un tercero,
 * «Liquidada», que pertenece al cierre financiero de un evento y sobre una
 * sucursal no significaría nada.
 */
class BranchesController extends Controller
{
    use AuthorizesBusinessPanel;

    public function index(Request $request): View
    {
        $this->negocioDe($request, Permission::BranchesManage->value);

        return view('business.branches', [
            'sucursales' => Branch::query()->orderBy('name')->get(),
            'cajasAbiertas' => CashSession::query()
                ->where('status', CashSessionStatus::Open->value)
                ->pluck('operating_unit_id')
                ->all(),
            'tipos' => OperatingUnitKind::cases(),
            'estados' => [OperatingUnitStatus::Active, OperatingUnitStatus::Closed],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::BranchesManage->value);

        $datos = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('operating_units', 'name')
                    ->where('tenant_id', $negocio->id)
                    ->whereNull('event_id'),
            ],
            'kind' => ['required', Rule::enum(OperatingUnitKind::class)],
        ], [], ['name' => 'nombre']);

        app(CreateBranch::class)(
            $datos['name'],
            OperatingUnitKind::from($datos['kind']),
        );

        return back()->with('status', 'Sucursal creada.');
    }

    public function update(Request $request, int $branch): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::BranchesManage->value);

        // Se resuelve AQUÍ y no por route model binding: los bindings se
        // sustituyen antes de que SetTenantContext fije la cuenta, así que
        // el scope no tendría contra qué acotar. Es la convención de todas
        // las puertas del proyecto.
        $branch = Branch::query()->findOrFail($branch);

        $datos = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('operating_units', 'name')
                    ->where('tenant_id', $negocio->id)
                    ->whereNull('event_id')
                    ->ignore($branch->id),
            ],
            'kind' => ['required', Rule::enum(OperatingUnitKind::class)],
            'status' => ['required', Rule::in([
                OperatingUnitStatus::Active->value,
                OperatingUnitStatus::Closed->value,
            ])],
        ], [], ['name' => 'nombre']);

        // Cerrar una sucursal con la caja abierta dejaría el turno del cajero
        // en el aire: sin poder cobrar y sin poder cuadrar.
        if ($datos['status'] === OperatingUnitStatus::Closed->value
            && CashSession::query()
                ->where('operating_unit_id', $branch->id)
                ->where('status', CashSessionStatus::Open->value)
                ->exists()) {
            return back()->withErrors([
                'status' => 'Esta sucursal tiene una caja abierta. Ciérrala desde el POS antes de cerrar la sucursal.',
            ]);
        }

        app(UpdateBranch::class)(
            $branch,
            $datos['name'],
            OperatingUnitKind::from($datos['kind']),
            OperatingUnitStatus::from($datos['status']),
        );

        return back()->with('status', 'Sucursal actualizada.');
    }
}
