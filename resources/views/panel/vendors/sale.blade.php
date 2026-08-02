@extends($layoutVenta ?? $panelLayout)

@section('title', 'Orden '.$sale->publicNumber().' — '.$vendor->name)

@section('content')
    @php
        $esEvento = $sale->operatingUnit?->event_id !== null;
        $fechaLocal = fn ($instante) => $instante?->timezone($tz)->format('d/m/Y h:i a');
        $estadoColor = match ($sale->status->value) {
            'paid' => 'bg-teal-500',
            'void' => 'bg-red-500',
            default => 'bg-amber-500',
        };
    @endphp

    {{-- Miga y encabezado --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-2">
        <div>
            <a href="{{ $volver ?? route('panel.vendors.show', $vendor) }}" class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-800">
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $vendor->name }}
            </a>
            <h1 class="mt-1 text-xl font-medium text-gray-800">
                Orden <span class="font-mono">{{ $sale->publicNumber() }}</span>
                <span class="ml-1 text-sm font-normal text-gray-500">· {{ $sale->channel->getLabel() }}</span>
            </h1>
        </div>
        <button type="button" onclick="window.print()" class="inline-flex items-center gap-x-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[13px] text-gray-700 shadow-2xs hover:bg-gray-50">
            <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
            Imprimir
        </button>
    </div>

    {{-- Tarjeta principal (patrón order-details: marco + tarjetas interiores) --}}
    <div class="space-y-2 rounded-2xl border border-gray-200 bg-gray-50 p-1.5">

        {{-- Cabecera de metadatos --}}
        <div class="px-4 py-3 sm:px-6">
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-5">
                <div>
                    <p class="mb-1 text-xs text-gray-500">Estado</p>
                    <p class="flex items-center text-[13px] font-medium text-gray-800">
                        <span class="me-1.5 inline-block size-2 shrink-0 rounded-full {{ $estadoColor }}"></span>
                        {{ $sale->status->getLabel() }}
                    </p>
                </div>
                <div>
                    <p class="mb-1 text-xs text-gray-500">Orden</p>
                    <p class="font-mono text-[13px] font-medium text-gray-800">{{ $sale->publicNumber() }}</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">{{ $sale->channel->getLabel() }}</p>
                </div>
                <div>
                    <p class="mb-1 text-xs text-gray-500">{{ $sale->status->value === 'paid' ? 'Cobrada' : 'Creada' }}</p>
                    <p class="text-[13px] font-medium text-gray-800">{{ $fechaLocal($sale->paid_at ?? $sale->created_at) }}</p>
                </div>
                <div>
                    <p class="mb-1 text-xs text-gray-500">Total</p>
                    <p class="text-[13px] font-medium text-gray-800">RD$ {{ number_format($sale->total_cents / 100, 2) }}</p>
                </div>
            </div>
        </div>

        {{-- Tarjeta: dónde se vendió / pago / resumen --}}
        <div class="rounded-xl bg-white p-4 shadow-2xs sm:p-6">
            <div class="grid grid-cols-1 gap-x-3 gap-y-7 sm:grid-cols-2 md:grid-cols-3">

                {{-- Dónde se vendió --}}
                <div>
                    <p class="mb-2 font-medium text-gray-800">Dónde se vendió</p>
                    <ul class="space-y-1">
                        <li class="text-sm text-gray-800">{{ $vendor->name }}</li>
                        <li class="text-sm text-gray-600">{{ $sale->operatingUnit?->name }} · {{ $esEvento ? 'puesto de evento' : 'sucursal' }}</li>
                        @if ($esEvento && $sale->operatingUnit?->event)
                            <li class="text-sm text-gray-600">{{ $sale->operatingUnit->event->name }}</li>
                        @endif
                        <li class="mt-2 text-sm text-gray-600">Caja #{{ $sale->cash_session_id }} — {{ $sale->cashSession?->status?->value === 'open' ? 'abierta' : 'cerrada' }}</li>
                        <li class="text-sm text-gray-600">Cajero: {{ $sale->user?->name ?? '—' }}</li>
                        <li class="mt-2 font-mono text-[11px] text-gray-400" title="Referencia técnica de la sincronización">ref {{ $sale->client_ref }}</li>
                    </ul>
                </div>

                {{-- Pago --}}
                <div>
                    <p class="mb-2 font-medium text-gray-800">Pago</p>
                    @if ($payment !== null)
                        <div class="mb-2 flex items-center gap-x-2">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">{{ $payment->method->getLabel() }}</span>
                            <span class="text-sm text-gray-800">RD$ {{ number_format($payment->amount_cents / 100, 2) }}</span>
                        </div>
                        @if ($payment->tendered_cents !== null && $payment->tendered_cents !== $payment->amount_cents)
                            <p class="text-sm text-gray-600">Recibido: RD$ {{ number_format($payment->tendered_cents / 100, 2) }}</p>
                            <p class="text-sm text-gray-600">Vuelto: RD$ {{ number_format($payment->change_cents / 100, 2) }}</p>
                        @endif
                        <p class="mt-2 text-xs text-gray-500">{{ $fechaLocal($payment->created_at) }}</p>
                    @elseif ($sale->status->value === 'void')
                        <p class="text-sm text-red-700">Anulada {{ $fechaLocal($sale->voided_at) }}</p>
                        @if ($sale->void_reason)
                            <p class="mt-1 text-sm text-gray-600">Motivo: {{ $sale->void_reason }}</p>
                        @endif
                    @else
                        <p class="text-sm text-gray-500">Sin cobro todavía: la orden sigue abierta en el POS.</p>
                    @endif

                    @if ($sale->refunds->isNotEmpty())
                        <div class="mt-3 border-t border-gray-200 pt-3">
                            <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-amber-700">Reembolsos</p>
                            @foreach ($sale->refunds as $refund)
                                <div class="mb-1.5 text-sm">
                                    <span class="font-medium text-gray-800">RD$ {{ number_format($refund->amount_cents / 100, 2) }}</span>
                                    <span class="text-gray-500">· {{ $refund->method->getLabel() }}</span>
                                    <p class="text-xs text-gray-500">{{ $refund->reason }} — {{ $refund->user?->name ?? 'sistema' }}, {{ $fechaLocal($refund->created_at) }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Resumen --}}
                <div>
                    <p class="mb-2 font-medium text-gray-800">Resumen</p>
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <span class="text-[13px] text-gray-600">Subtotal</span>
                            <span class="text-end text-[13px] font-semibold text-gray-800">RD$ {{ number_format($sale->subtotal_cents / 100, 2) }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <span class="text-[13px] text-gray-600">{{ $sale->itbis_mode->value === 'added' ? 'ITBIS (18 %)' : 'ITBIS incluido (18 %)' }}</span>
                            <span class="text-end text-[13px] font-semibold text-gray-800">RD$ {{ number_format($sale->itbis_cents / 100, 2) }}</span>
                        </div>
                        @if ($sale->tip_cents > 0)
                            <div class="grid grid-cols-2 gap-2">
                                <span class="text-[13px] text-gray-600">Propina legal (10 %)</span>
                                <span class="text-end text-[13px] font-semibold text-gray-800">RD$ {{ number_format($sale->tip_cents / 100, 2) }}</span>
                            </div>
                        @endif
                        <div class="grid grid-cols-2 gap-2 border-t border-gray-200 pt-2">
                            <span class="text-sm font-medium text-gray-800">Total</span>
                            <span class="text-end font-semibold text-gray-800"><span class="text-xs">RD$</span> {{ number_format($sale->total_cents / 100, 2) }}</span>
                        </div>
                        @if ($sale->refunds->isNotEmpty())
                            @php($devuelto = (int) $sale->refunds->sum('amount_cents'))
                            <div class="grid grid-cols-2 gap-2">
                                <span class="text-[13px] text-amber-700">Reembolsado</span>
                                <span class="text-end text-[13px] font-semibold text-amber-700">− RD$ {{ number_format($devuelto / 100, 2) }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <span class="text-[13px] font-medium text-gray-800">Neto</span>
                                <span class="text-end text-[13px] font-semibold text-gray-800">RD$ {{ number_format(($sale->total_cents - $devuelto) / 100, 2) }}</span>
                            </div>
                        @endif
                        @if ($sale->commission_bps !== null)
                            <div class="grid grid-cols-2 gap-2">
                                <span class="text-[13px] text-teal-700">Tu comisión ({{ rtrim(rtrim(number_format($sale->commission_bps / 100, 2), '0'), '.') }} %)</span>
                                <span class="text-end text-[13px] font-semibold text-teal-700">RD$ {{ number_format(round($sale->total_cents * $sale->commission_bps / 10000) / 100, 2) }}</span>
                            </div>
                        @endif
                    </div>
                    <p class="mt-3 text-xs text-gray-500">
                        @if ($sale->itbis_mode->value === 'added')
                            El precio de carta es la base y el ITBIS se sumó al cobrar, línea a línea;
                        @else
                            El ITBIS iba incluido en el precio y se desglosa línea a línea;
                        @endif
                        la propina es sobre la base sin impuesto.
                    </p>
                </div>
            </div>
        </div>

        {{-- Tarjeta: línea de tiempo + lo vendido --}}
        <div class="rounded-xl bg-white p-4 shadow-2xs sm:p-6">

            {{-- Línea de tiempo (patrón de barras del template) --}}
            <div class="mb-6 border-b border-gray-200 pb-6">
                <div class="grid grid-cols-2 gap-x-3">
                    <div>
                        <p class="mb-2 flex items-center gap-x-1.5 text-sm text-gray-800">
                            <svg class="size-4 shrink-0 text-teal-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            Creada <span class="text-gray-500">{{ $fechaLocal($sale->created_at) }}</span>
                        </p>
                        <div class="flex h-1.5 w-full overflow-hidden rounded-sm bg-gray-100">
                            <div class="rounded-sm bg-teal-500" style="width: 100%"></div>
                        </div>
                    </div>
                    <div>
                        @if ($sale->status->value === 'paid')
                            <p class="mb-2 flex items-center gap-x-1.5 text-sm text-gray-800">
                                <svg class="size-4 shrink-0 text-teal-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                Cobrada <span class="text-gray-500">{{ $fechaLocal($sale->paid_at) }}</span>
                            </p>
                            <div class="flex h-1.5 w-full overflow-hidden rounded-sm bg-gray-100">
                                <div class="rounded-sm bg-teal-500" style="width: 100%"></div>
                            </div>
                        @elseif ($sale->status->value === 'void')
                            <p class="mb-2 text-sm font-medium text-red-700">Anulada <span class="font-normal text-gray-500">{{ $fechaLocal($sale->voided_at) }}</span></p>
                            <div class="flex h-1.5 w-full overflow-hidden rounded-sm bg-gray-100">
                                <div class="rounded-sm bg-red-500" style="width: 100%"></div>
                            </div>
                        @else
                            <p class="mb-2 flex items-center gap-x-1.5 text-sm text-amber-700">
                                <span class="relative flex">
                                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-amber-500"></span>
                                </span>
                                Abierta en el POS
                            </p>
                            <div class="flex h-1.5 w-full overflow-hidden rounded-sm bg-gray-100">
                                <div class="rounded-sm bg-amber-400" style="width: 35%"></div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Lo vendido --}}
            <p class="mb-4 font-medium text-gray-800">Lo vendido</p>
            <div>
                @foreach ($sale->lines as $linea)
                    <div class="flex flex-row gap-4 border-b border-gray-200 pb-5 mb-5 last:mb-0 last:border-b-0 last:pb-0">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-lg font-semibold text-sky-700">
                            {{ Str::upper(Str::substr($linea->product_name, 0, 1)) }}
                        </div>
                        <div class="grow">
                            <div class="mb-2 sm:flex sm:gap-3">
                                <div class="grow">
                                    <p class="text-gray-800">{{ $linea->product_name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-500">RD$ {{ number_format($linea->unit_price_cents / 100, 2) }} c/u</p>
                                </div>
                                <p class="font-medium text-gray-800">RD$ {{ number_format($linea->total_cents / 100, 2) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <div>
                                    <p class="text-xs text-gray-500">Cantidad</p>
                                    <p class="text-sm text-gray-800">{{ rtrim(rtrim(number_format((float) $linea->quantity, 3), '0'), '.') }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">ITBIS de la línea</p>
                                    @if ($linea->itbis_cents > 0)
                                        <p class="text-sm text-gray-800">RD$ {{ number_format($linea->itbis_cents / 100, 2) }}</p>
                                    @elseif ($linea->total_cents === 0)
                                        {{-- Una cortesía gravada también da 0: no es una exención. --}}
                                        <p class="text-sm text-gray-800">RD$ 0.00</p>
                                    @else
                                        <p class="text-sm font-medium text-violet-700">Exenta</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
