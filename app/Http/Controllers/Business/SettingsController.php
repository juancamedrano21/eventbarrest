<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Rules\ValidRnc;
use App\Domains\Sales\Enums\ItbisMode;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Los datos de la cuenta y su regla fiscal.
 *
 * Hasta ahora `itbis_mode` solo se tocaba desde el panel del superadmin, así
 * que un bar facturaba siempre con el impuesto incluido por el valor por
 * defecto — correcto para un bar de barra, equivocado para un restaurante
 * que cobra el 18 % por fuera. Esta pantalla es la casa de `fiscal.manage`,
 * un permiso que existía en el catálogo sin que nadie lo comprobara.
 *
 * Cambiar la modalidad NO reescribe lo ya cobrado: cada venta guarda la suya
 * congelada, porque su comprobante ya salió impreso.
 */
class SettingsController extends Controller
{
    use AuthorizesBusinessPanel;

    public function edit(Request $request): View
    {
        $negocio = $this->negocioDe($request, Permission::FiscalManage->value);

        return view('business.settings', [
            'negocio' => $negocio,
            'modos' => ItbisMode::cases(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::FiscalManage->value);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rnc' => ['nullable', 'string', new ValidRnc, Rule::unique('tenants', 'rnc')->ignore($negocio->id)],
            'itbis_mode' => ['required', Rule::enum(ItbisMode::class)],
        ], [
            'rnc.unique' => 'Ese RNC ya está registrado en otra cuenta.',
        ], ['name' => 'nombre']);

        $negocio->update([
            'name' => $data['name'],
            'rnc' => filled($data['rnc'] ?? null) ? $data['rnc'] : null,
            'itbis_mode' => ItbisMode::from($data['itbis_mode']),
        ]);

        return back()->with('status', 'Ajustes guardados.');
    }
}
