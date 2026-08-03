<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Kitchen\Queries\KitchenTimings;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cuánto se tarda en despachar, visto por el organizador: todos los comercios
 * del evento en la misma tabla. Es la comparación que solo él puede hacer —
 * cada comercio ve lo suyo y no tiene con qué compararse.
 *
 * Aquí no se calcula ni un segundo: de eso responde KitchenTimings. Lo único
 * que decide este controlador es QUÉ VENTANA se mide, que es la pregunta que
 * el informe no puede contestar por su cuenta.
 */
class EventTimingsController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $event): View
    {
        // Los tiempos son un número de la cuenta, igual que el dinero del
        // dashboard: comparar el desempeño de un comercio con el de otro es
        // justo lo que guarda ReportsViewTenant. Quien administra eventos
        // (EventsManage) organiza el festival; no por eso le toca leer los
        // tiempos de la gente de otro.
        $this->authorizeOrganizer($request, Permission::ReportsViewTenant);

        $record = Event::query()->findOrFail($event);

        $tz = (string) config('app.business_timezone');
        $rango = $request->query('rango') === 'hoy' ? 'hoy' : 'evento';

        [$desde, $hasta] = $rango === 'hoy'
            ? $this->hoy($tz)
            : $this->todoElEvento($record);

        return view('event-panel.events.timings', [
            'event' => $record,
            'rango' => $rango,
            'tz' => $tz,
            'informe' => app(KitchenTimings::class)->forEvent($record, $desde, $hasta),
        ]);
    }

    /**
     * El día que vive el festival, no el que dice UTC: una venta de las once
     * de la noche es de hoy aunque en Londres ya sea mañana.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function hoy(string $tz): array
    {
        return [today($tz)->utc(), today($tz)->addDay()->utc()];
    }

    /**
     * El evento entero. El final se estira hasta AHORA cuando el evento ya
     * cerró, y no es un descuido: el POS es offline-first y la ventana del
     * informe se corta por `paid_at`, así que una venta cobrada a las tres de
     * la mañana puede aterrizar en el servidor con el festival ya recogido.
     * Con un `ends_at` seco desaparecerían del informe justo las comandas de
     * la peor hora de la noche, que son las que hay que ver.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function todoElEvento(Event $event): array
    {
        $fin = $event->ends_at;
        $ahora = now();

        return [$event->starts_at, $fin->lessThan($ahora) ? $ahora : $fin];
    }
}
