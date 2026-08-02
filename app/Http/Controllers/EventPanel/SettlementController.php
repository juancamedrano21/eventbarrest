<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Actions\SettleEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventSettlement;
use App\Domains\EventManagement\Queries\SettlementFigures;
use App\Domains\EventManagement\Queries\SettlementRow;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La liquidación del evento: qué vendió cada comercio y qué le toca al
 * organizador.
 *
 * Antes de liquidar, la pantalla enseña un BORRADOR calculado al vuelo —el
 * organizador tiene que poder ver cómo va la cuenta mientras el festival
 * ocurre. Después enseña las cifras congeladas, que son otra cosa: un
 * documento sobre el que se cobra.
 */
class SettlementController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $event): View
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Event::query()->findOrFail($event);
        $liquidado = $record->status === EventStatus::Settled;

        $liquidaciones = EventSettlement::query()
            ->where('event_id', $record->id)
            ->with(['vendor', 'settledBy', 'paidBy'])
            ->get();

        // Las dos fuentes se normalizan a la MISMA forma: la vista no tiene
        // que saber si mira un documento cerrado o un borrador.
        $filas = $liquidado
            ? $liquidaciones->map(fn (EventSettlement $fila): array => [
                'settlement_id' => $fila->id,
                'vendor_name' => $fila->vendor->name,
                'orders_count' => $fila->orders_count,
                'gross_cents' => $fila->gross_cents,
                'refunded_cents' => $fila->refunded_cents,
                'tip_cents' => $fila->tip_cents,
                'commission_base' => $fila->commission_base,
                'commission_bps' => $fila->commission_bps,
                'commission_base_cents' => $fila->commission_base_cents,
                'commission_cents' => $fila->commission_cents,
                'net_cents' => $fila->net_cents,
                'paid_at' => $fila->paid_at,
                'paid_by' => $fila->paidBy?->name,
                'payment_note' => $fila->payment_note,
            ])->values()
            : app(SettlementFigures::class)->forEvent($record)
                ->map(fn (SettlementRow $fila): array => [
                    'settlement_id' => null,
                    'vendor_name' => $fila->vendorName,
                    'orders_count' => $fila->ordersCount,
                    'gross_cents' => $fila->grossCents,
                    'refunded_cents' => $fila->refundedCents,
                    'tip_cents' => $fila->tipCents,
                    'commission_base' => $fila->commissionBase,
                    'commission_bps' => $fila->commissionBps,
                    'commission_base_cents' => $fila->commissionBaseCents,
                    'commission_cents' => $fila->commissionCents,
                    'net_cents' => $fila->netCents,
                    'paid_at' => null,
                    'paid_by' => null,
                    'payment_note' => null,
                ])->values();

        return view('event-panel.events.settlement', [
            'event' => $record,
            'liquidado' => $liquidado,
            'filas' => $filas,
            'totales' => (object) [
                'gross' => (int) $filas->sum('gross_cents'),
                'refunded' => (int) $filas->sum('refunded_cents'),
                'commission' => (int) $filas->sum('commission_cents'),
                'net' => (int) $filas->sum('net_cents'),
                'cobrado' => (int) $filas->filter(fn (array $f): bool => $f['paid_at'] !== null)->sum('commission_cents'),
                'porCobrar' => (int) $filas->filter(fn (array $f): bool => $f['paid_at'] === null)->sum('commission_cents'),
            ],
            'settledAt' => $liquidaciones->first()?->settled_at,
            'settledBy' => $liquidaciones->first()?->settledBy?->name,
            'tz' => (string) config('app.business_timezone'),
        ]);
    }

    /** Cerrar la cuenta: calcular, congelar y dejar el evento liquidado. */
    public function store(Request $request, int $event): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsSettle);

        $record = Event::query()->findOrFail($event);

        try {
            $cuantas = app(SettleEvent::class)($record, $request->user());
        } catch (VendorException $e) {
            return back()->withErrors(['settle' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            $cuantas === 0
                ? 'Evento liquidado. No hubo ventas que repartir.'
                : "Evento liquidado: {$cuantas} comercio(s) con su estado de cuenta cerrado.",
        );
    }

    /** Anotar que un comercio ya pagó su comisión. */
    public function markPaid(Request $request, int $event, int $settlement): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsSettle);

        $record = EventSettlement::query()
            ->where('event_id', $event)
            ->findOrFail($settlement);

        $data = $request->validate([
            'payment_note' => ['nullable', 'string', 'max:255'],
        ], [], ['payment_note' => 'nota']);

        if ($record->isPaid()) {
            return back()->withErrors(['payment' => 'Esa comisión ya estaba marcada como cobrada.']);
        }

        $record->update([
            'paid_at' => now(),
            'paid_by' => $request->user()?->id,
            'payment_note' => $data['payment_note'] ?? null,
        ]);

        return back()->with('status', 'Comisión marcada como cobrada.');
    }
}
