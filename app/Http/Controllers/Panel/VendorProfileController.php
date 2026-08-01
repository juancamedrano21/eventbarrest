<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El perfil del comercio en el panel nuevo (Blade + Preline): la vista del
 * organizador. Toda la lógica vive en las acciones de dominio de siempre —
 * este controlador solo autoriza, delega y presenta.
 */
class VendorProfileController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $vendor): View
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $record = Vendor::query()->with(['users.roles'])->findOrFail($vendor);

        return view('panel.vendors.show', [
            'vendor' => $record,
            'participations' => $record->events()->orderBy('starts_at')->get(),
            'outlets' => EventOutlet::query()
                ->where('vendor_id', $record->id)
                ->with('event')
                ->orderBy('name')
                ->get(),
            'products' => app(VendorContext::class)->runAs(
                $record,
                fn () => $record->products()->with('category')->orderBy('name')->get(),
            ),
            'vendorRoles' => RoleTemplate::optionsForVendorStaff(),
            'invitableEvents' => Event::query()
                ->whereNotIn('id', $record->events()->select('events.id'))
                ->orderBy('starts_at')
                ->pluck('name', 'id'),
            'roleLabels' => RoleTemplate::query()->pluck('label', 'name'),
        ]);
    }

    public function storeUser(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::UsersManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string'],
        ]);

        app(CreateTenantUser::class)(
            $record->tenant,
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role'],
            $record,
            $request->user(),
            $data['username'] ?? null,
        );

        return back()->with('status', 'Usuario del comercio creado.');
    }

    public function invite(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        app(InviteVendorToEvent::class)(
            Event::query()->findOrFail((int) $data['event_id']),
            $record,
            (int) round(((float) ($data['commission'] ?? 0)) * 100),
        );

        return back()->with('status', 'Comercio invitado al evento.');
    }

    public function storeOutlet(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventOutletsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string'],
        ]);

        app(CreateEventOutlet::class)(
            Event::query()->findOrFail((int) $data['event_id']),
            $record,
            $data['name'],
            OperatingUnitKind::from($data['kind']),
        );

        return back()->with('status', 'Puesto creado.');
    }
}
