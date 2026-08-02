<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Actions\AllocateToEvent;
use App\Domains\Inventory\Actions\ReturnFromEvent;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Queries\EventStockLine;
use App\Domains\Inventory\Queries\EventStockReconciliation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La mercancía del evento: qué se le entregó a cada puesto y qué queda por
 * explicar al cerrar.
 *
 * Las escrituras entran EN el comercio dueño del puesto: los insumos llevan
 * `vendor_id` y sin ese contexto el scope no encontraría ninguno.
 */
class EventStockController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $event): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Event::query()->findOrFail($event);

        $puestos = EventOutlet::query()
            ->where('event_id', $record->id)
            ->with('vendor')
            ->orderBy('name')
            ->get();

        $lineas = app(EventStockReconciliation::class)->forEvent($record);

        return view('event-panel.events.stock', [
            'event' => $record,
            'puestos' => $puestos,
            'lineas' => $lineas,
            // Los insumos de cada comercio, para el selector: son suyos y el
            // scope los esconde si no se entra en su contexto.
            'insumosPorComercio' => $puestos
                ->pluck('vendor')
                ->filter()
                ->unique('id')
                ->mapWithKeys(fn ($vendor): array => [
                    $vendor->id => app(VendorContext::class)->runAs(
                        $vendor,
                        fn () => InventoryItem::query()->orderBy('name')->get(['id', 'name', 'base_unit']),
                    ),
                ]),
            'puedeMover' => (bool) $request->user()?->can(Permission::InventoryAllocateToEvent->value),
            'faltantes' => $lineas->filter(fn (EventStockLine $l): bool => abs($l->missing) > 0.0001)->count(),
        ]);
    }

    public function allocate(Request $request, int $event): RedirectResponse
    {
        return $this->mover($request, $event, entrega: true);
    }

    public function returnStock(Request $request, int $event): RedirectResponse
    {
        return $this->mover($request, $event, entrega: false);
    }

    /**
     * Entregar y devolver son el mismo formulario con el signo cambiado: se
     * validan y se resuelven igual, y solo difieren en qué Action se llama.
     */
    private function mover(Request $request, int $event, bool $entrega): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::InventoryAllocateToEvent);

        $record = Event::query()->findOrFail($event);

        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'inventory_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'counterpart_id' => ['nullable', 'integer', 'different:outlet_id'],
        ], [
            'counterpart_id.different' => 'El puesto y la bodega tienen que ser distintos.',
        ], ['quantity' => 'cantidad', 'outlet_id' => 'puesto']);

        // Ambas unidades tienen que ser puestos DE ESTE evento.
        $puesto = EventOutlet::query()
            ->where('event_id', $record->id)
            ->findOrFail((int) $data['outlet_id']);

        $contraparte = filled($data['counterpart_id'] ?? null)
            ? EventOutlet::query()->where('event_id', $record->id)->findOrFail((int) $data['counterpart_id'])
            : null;

        $vendor = $puesto->vendor;

        try {
            app(VendorContext::class)->runAs($vendor, function () use ($entrega, $puesto, $contraparte, $data): void {
                $item = InventoryItem::query()->findOrFail((int) $data['inventory_item_id']);

                $entrega
                    ? app(AllocateToEvent::class)($puesto, $item, (float) $data['quantity'], $contraparte)
                    : app(ReturnFromEvent::class)($puesto, $item, (float) $data['quantity'], $contraparte);
            });
        } catch (InventoryException $e) {
            return back()->withErrors(['stock' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            $entrega
                ? 'Entrega registrada: el puesto ya responde de esa mercancía.'
                : 'Devolución registrada.',
        );
    }
}
