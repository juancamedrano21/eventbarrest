<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Rules\ValidRnc;
use App\Domains\Sales\Enums\ItbisMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** El directorio de comercios del organizador, en el panel nuevo. */
class VendorsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function index(Request $request): View
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        return view('event-panel.vendors.index', [
            'vendors' => Vendor::query()->withCount('events')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rnc' => ['nullable', 'string', 'max:20', new ValidRnc],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $vendor = app(CreateVendor::class)(
            $data['name'],
            filled($data['rnc'] ?? null) ? ValidRnc::normalize($data['rnc']) : null,
            $data['contact_name'] ?? null,
            $data['contact_phone'] ?? null,
        );

        return redirect()
            ->route('event-panel.vendors.show', $vendor)
            ->with('status', 'Comercio creado: ahora invítalo a un evento y crea su equipo.');
    }

    public function update(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rnc' => ['nullable', 'string', 'max:20', new ValidRnc],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'in:draft,active,suspended'],
            'vendor_type_id' => ['nullable', 'integer', 'exists:vendor_types,id'],
            'food_type_id' => ['nullable', 'integer', 'exists:food_types,id'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // Vacío = hereda la regla fiscal de la cuenta.
            'itbis_mode' => ['nullable', Rule::enum(ItbisMode::class)],
        ]);

        // Dos formularios distintos escriben aquí (el modal «Editar» y la
        // pestaña Configuraciones) y cada uno trae SUS campos: lo que no
        // viene en la petición no se toca. Si no, editar un teléfono
        // borraba en silencio la clasificación y la regla fiscal.
        $update = [
            'name' => $data['name'],
            'rnc' => filled($data['rnc'] ?? null) ? ValidRnc::normalize($data['rnc']) : null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'status' => VendorStatus::from($data['status']),
        ];

        foreach (['vendor_type_id', 'food_type_id'] as $campo) {
            if ($request->has($campo)) {
                $update[$campo] = $data[$campo] ?? null;
            }
        }

        if ($request->has('itbis_mode')) {
            // Vacío = vuelve a heredar la regla fiscal de la cuenta.
            $update['itbis_mode'] = filled($data['itbis_mode'] ?? null)
                ? ItbisMode::from($data['itbis_mode'])
                : null;
        }

        if ($request->hasFile('logo')) {
            $update['logo_path'] = $request->file('logo')->store('vendor-logos', 'public');
        }

        $record->update($update);

        return back()->with('status', 'Datos del comercio actualizados.');
    }
}
