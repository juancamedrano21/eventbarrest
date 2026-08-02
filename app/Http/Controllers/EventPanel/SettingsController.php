<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Enums\CommissionBase;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Rules\ValidRnc;
use App\Domains\Sales\Enums\ItbisMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Los ajustes de la cuenta del organizador: sus datos, su regla de ITBIS y
 * —lo importante— sobre qué dinero cobra su comisión.
 *
 * Esa última decisión mueve cifras de verdad: sobre una venta de RD$1,000 con
 * impuesto incluido y propina, un 10 % pactado son RD$84.75 o RD$108.48 según
 * lo que se elija. Rige de aquí en adelante y nunca hacia atrás: cada venta
 * congela la regla con la que se cobró.
 */
class SettingsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function edit(Request $request): View
    {
        $tenant = $this->authorizeOrganizer($request, Permission::FiscalManage);

        return view('event-panel.settings', [
            'cuenta' => $tenant,
            'modos' => ItbisMode::cases(),
            'bases' => CommissionBase::cases(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->authorizeOrganizer($request, Permission::FiscalManage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rnc' => ['nullable', 'string', new ValidRnc, Rule::unique('tenants', 'rnc')->ignore($tenant->id)],
            'itbis_mode' => ['required', Rule::enum(ItbisMode::class)],
            'commission_base' => ['required', Rule::enum(CommissionBase::class)],
        ], [
            'rnc.unique' => 'Ese RNC ya está registrado en otra cuenta.',
        ], ['name' => 'nombre', 'commission_base' => 'base de la comisión']);

        $tenant->update([
            'name' => $data['name'],
            'rnc' => filled($data['rnc'] ?? null) ? $data['rnc'] : null,
            'itbis_mode' => ItbisMode::from($data['itbis_mode']),
            'commission_base' => CommissionBase::from($data['commission_base']),
        ]);

        return back()->with('status', 'Ajustes guardados. Rigen para las ventas de aquí en adelante.');
    }
}
