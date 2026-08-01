<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Rules\ValidRnc;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** El directorio de comercios del organizador, en el panel nuevo. */
class VendorsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function index(Request $request): View
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        return view('panel.vendors.index', [
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
            ->route('panel.vendors.show', $vendor)
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
        ]);

        $record->update([
            'name' => $data['name'],
            'rnc' => filled($data['rnc'] ?? null) ? ValidRnc::normalize($data['rnc']) : null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'status' => VendorStatus::from($data['status']),
        ]);

        return back()->with('status', 'Datos del comercio actualizados.');
    }
}
