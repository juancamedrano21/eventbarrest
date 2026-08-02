<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\UpdateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Models\CashSession;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Los eventos del organizador en el panel nuevo. */
class EventsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function index(Request $request): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        return view('event-panel.events.index', [
            'events' => Event::query()->withCount('vendors')->orderByDesc('starts_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $event = app(CreateEvent::class)(
            $data['name'],
            new \DateTimeImmutable($data['starts_at']),
            new \DateTimeImmutable($data['ends_at']),
            $data['venue'] ?? null,
            EventStatus::Active,
        );

        return redirect()
            ->route('event-panel.events.show', $event)
            ->with('status', 'Evento creado: invita a sus comercios desde el perfil de cada uno.');
    }

    /**
     * Editar un evento, incluido su ESTADO. Sin esto un festival no se puede
     * cerrar ni liquidar desde ninguna pantalla del sistema.
     *
     * Liquidar pide su propio permiso: es el corte financiero, no un cambio
     * de rótulo.
     */
    public function update(Request $request, int $event): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Event::query()->findOrFail($event);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['required', Rule::enum(EventStatus::class)],
        ], [], ['name' => 'nombre', 'venue' => 'lugar', 'status' => 'estado']);

        $estado = EventStatus::from($data['status']);

        if ($estado === EventStatus::Settled && $record->status !== EventStatus::Settled) {
            $this->authorizeOrganizer($request, Permission::EventsSettle);
        }

        // Cerrar con una caja abierta dejaría a un cajero a mitad de turno.
        if (in_array($estado, [EventStatus::Closed, EventStatus::Settled], true)
            && CashSession::query()
                ->where('status', CashSessionStatus::Open->value)
                ->whereIn('operating_unit_id', EventOutlet::query()
                    ->where('event_id', $record->id)
                    ->select('id'))
                ->exists()) {
            return back()->withErrors([
                'status' => 'Hay cajas abiertas en este evento. Ciérralas desde el POS antes de cerrarlo.',
            ]);
        }

        app(UpdateEvent::class)(
            $record,
            $data['name'],
            new \DateTimeImmutable($data['starts_at']),
            new \DateTimeImmutable($data['ends_at']),
            $data['venue'] ?? null,
            $estado,
        );

        return back()->with('status', 'Evento actualizado.');
    }

    public function show(Request $request, int $event): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Event::query()->findOrFail($event);

        return view('event-panel.events.show', [
            'event' => $record,
            'participants' => $record->vendors()->orderBy('name')->get(),
            'outlets' => EventOutlet::query()
                ->where('event_id', $record->id)
                ->with('vendor')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
