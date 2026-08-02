@extends($panelLayout)

@section('title', 'Liquidación — '.$event->name)

@section('content')
    @php
        $moneda = fn (int $cents): string => 'RD$ '.number_format($cents / 100, 2);
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('event-panel.events.show', $event) }}" class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-800">
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $event->name }}
            </a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">Liquidación</h1>
            <p class="mt-1 text-sm text-gray-500">
                @if ($liquidado)
                    Cerrada el {{ $settledAt?->timezone($tz)->format('d/m/Y, H:i') }}
                    @if ($settledBy) por {{ $settledBy }} @endif · las cifras ya no cambian.
                @else
                    Borrador: se recalcula cada vez que abres esta pantalla. Al liquidar se congela.
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Imprimir</button>
            @unless ($liquidado)
                @can('events.settle')
                    <button type="button" data-hs-overlay="#modal-liquidar" aria-haspopup="dialog"
                        class="rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sky-500">Liquidar evento</button>
                @endcan
            @endunless
        </div>
    </div>

    {{-- Los cuatro números del evento --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Vendido por los comercios</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">{{ $moneda($totales->gross - $totales->refunded) }}</p>
            @if ($totales->refunded > 0)
                <p class="mt-1 text-xs text-gray-500">{{ $moneda($totales->gross) }} cobrado − {{ $moneda($totales->refunded) }} devuelto</p>
            @endif
        </div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-5">
            <p class="text-xs uppercase tracking-wide text-sky-700">Tu comisión</p>
            <p class="mt-1 text-2xl font-semibold text-sky-900">{{ $moneda($totales->commission) }}</p>
            <p class="mt-1 text-xs text-sky-700">Lo que te corresponde del evento</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Queda para los comercios</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">{{ $moneda($totales->net) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Comisión por cobrar</p>
            <p class="mt-1 text-2xl font-semibold {{ $totales->porCobrar > 0 ? 'text-amber-600' : 'text-teal-700' }}">
                {{ $moneda($totales->porCobrar) }}
            </p>
            @if ($liquidado)
                <p class="mt-1 text-xs text-gray-500">{{ $moneda($totales->cobrado) }} ya cobrado</p>
            @else
                <p class="mt-1 text-xs text-gray-500">Se podrá cobrar al liquidar</p>
            @endif
        </div>
    </div>

    @if ($filas->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-5 py-12 text-center">
            <p class="text-sm text-gray-500">Ningún comercio vendió en este evento.</p>
            <p class="mt-1 text-sm text-gray-400">No hay nada que repartir.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Comercio</th>
                            <th class="px-5 py-3 text-right font-medium">Vendido</th>
                            <th class="px-5 py-3 text-right font-medium">Devuelto</th>
                            <th class="px-5 py-3 text-right font-medium">Base</th>
                            <th class="px-5 py-3 text-right font-medium">Comisión</th>
                            <th class="px-5 py-3 text-right font-medium">Le queda</th>
                            @if ($liquidado)
                                <th class="px-5 py-3 font-medium">Cobro</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($filas as $fila)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-gray-800">{{ $fila['vendor_name'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $fila['orders_count'] }} {{ $fila['orders_count'] === 1 ? 'venta' : 'ventas' }}
                                        @if ($fila['tip_cents'] > 0) · {{ $moneda($fila['tip_cents']) }} de propina @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-800">{{ $moneda($fila['gross_cents']) }}</td>
                                <td class="px-5 py-3 text-right {{ $fila['refunded_cents'] > 0 ? 'text-red-600' : 'text-gray-400' }}">
                                    {{ $fila['refunded_cents'] > 0 ? '−'.$moneda($fila['refunded_cents']) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <span class="text-gray-800">{{ $moneda($fila['commission_base_cents']) }}</span>
                                    <span class="block text-xs text-gray-500">{{ $fila['commission_base']->getLabel() }}</span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <span class="font-medium text-sky-700">{{ $moneda($fila['commission_cents']) }}</span>
                                    <span class="block text-xs text-gray-500">{{ number_format($fila['commission_bps'] / 100, 2) }} %</span>
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-gray-800">{{ $moneda($fila['net_cents']) }}</td>
                                @if ($liquidado)
                                    <td class="px-5 py-3">
                                        @if ($fila['paid_at'])
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-2.5 py-0.5 text-xs text-teal-700">
                                                <span class="size-1.5 rounded-full bg-teal-500"></span>Cobrada
                                            </span>
                                            <span class="mt-1 block text-xs text-gray-500">
                                                {{ $fila['paid_at']->timezone($tz)->format('d/m/Y') }}
                                                @if ($fila['paid_by']) · {{ $fila['paid_by'] }} @endif
                                            </span>
                                            @if ($fila['payment_note'])
                                                <span class="block text-xs text-gray-400">{{ $fila['payment_note'] }}</span>
                                            @endif
                                        @else
                                            @can('events.settle')
                                                <button type="button" data-hs-overlay="#modal-cobro-{{ $fila['settlement_id'] }}" aria-haspopup="dialog"
                                                    class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Marcar cobrada</button>
                                            @else
                                                <span class="text-xs text-amber-600">Pendiente</span>
                                            @endcan
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 bg-gray-50">
                        <tr>
                            <td class="px-5 py-3 font-medium text-gray-800">Total</td>
                            <td class="px-5 py-3 text-right font-medium text-gray-800">{{ $moneda($totales->gross) }}</td>
                            <td class="px-5 py-3 text-right font-medium text-red-600">
                                {{ $totales->refunded > 0 ? '−'.$moneda($totales->refunded) : '—' }}
                            </td>
                            <td></td>
                            <td class="px-5 py-3 text-right font-semibold text-sky-700">{{ $moneda($totales->commission) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-gray-800">{{ $moneda($totales->net) }}</td>
                            @if ($liquidado)<td></td>@endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            La comisión se calcula con la regla y el porcentaje que cada venta congeló, no con los de hoy.
            Lo devuelto reduce la base en la misma proporción: no se cobra comisión sobre dinero que el comercio le devolvió al cliente.
        </p>
    @endif

    {{-- Liquidar --}}
    @unless ($liquidado)
        <div id="modal-liquidar" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
                <form method="POST" action="{{ route('event-panel.events.settle', $event) }}"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                    @csrf
                    <h3 class="mb-2 font-medium text-gray-800">Liquidar {{ $event->name }}</h3>
                    <p class="mb-4 text-sm text-gray-600">
                        Se cierra la cuenta de {{ $filas->count() }} comercio(s) con
                        <strong class="text-gray-800">{{ $moneda($totales->commission) }}</strong> de comisión para ti.
                    </p>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Las cifras quedan congeladas y el evento no admitirá más reembolsos.
                        Para devolver algo después habrá que reabrirlo.
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-liquidar">Cancelar</button>
                        <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Liquidar</button>
                    </div>
                </form>
            </div>
        </div>
    @endunless

    {{-- Marcar cobrada, una por comercio --}}
    @foreach ($filas as $fila)
        @if ($liquidado && ! $fila['paid_at'] && $fila['settlement_id'])
            <div id="modal-cobro-{{ $fila['settlement_id'] }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
                <div class="m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
                    <form method="POST" action="{{ route('event-panel.events.settlement.paid', [$event, $fila['settlement_id']]) }}"
                        class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                        @csrf
                        <h3 class="mb-1 font-medium text-gray-800">{{ $fila['vendor_name'] }}</h3>
                        <p class="mb-4 text-sm text-gray-600">
                            Comisión de <strong class="text-gray-800">{{ $moneda($fila['commission_cents']) }}</strong>.
                        </p>
                        <label for="nota-{{ $fila['settlement_id'] }}" class="mb-1.5 block text-sm text-gray-700">Nota <span class="text-gray-400">(opcional)</span></label>
                        <input id="nota-{{ $fila['settlement_id'] }}" name="payment_note" maxlength="255"
                            placeholder="Transferencia, efectivo, número de referencia…"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-cobro-{{ $fila['settlement_id'] }}">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Marcar cobrada</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
@endsection
