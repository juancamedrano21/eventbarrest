<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Los eventos del organizador en el panel nuevo. */
class EventsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function index(Request $request): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        return view('panel.events.index', [
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
            ->route('panel.events.show', $event)
            ->with('status', 'Evento creado: invita a sus comercios desde el perfil de cada uno.');
    }

    public function show(Request $request, int $event): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Event::query()->findOrFail($event);

        return view('panel.events.show', [
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
