@extends($panelLayout)

@section('title', $event->name)

@section('content')
    <div class="mb-8 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-widest text-gray-500">Evento</p>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">{{ $event->name }}</h1>
            <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                <span>{{ $event->starts_at->format('d M Y, H:i') }} → {{ $event->ends_at->format('d M Y, H:i') }}</span>
                <span>{{ $event->venue ?? '—' }}</span>
                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $event->status->getLabel() }}</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('event-panel.events.stock', $event) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Mercancía</a>
            <a href="{{ route('event-panel.events.timings', $event) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Tiempos</a>
            <a href="{{ route('event-panel.events.settlement', $event) }}"
                class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">
                {{ $event->status->value === 'settled' ? 'Ver liquidación' : 'Liquidación' }}
            </a>
            <button type="button" data-hs-overlay="#modal-evento" aria-haspopup="dialog"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Editar evento</button>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Comercios participantes <span class="ml-1 text-xs font-normal text-gray-500">se invitan desde el perfil de cada comercio</span></h2>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($participants as $vendor)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <a href="{{ route('event-panel.vendors.show', $vendor) }}" class="text-gray-800 hover:text-sky-600">{{ $vendor->name }}</a>
                        <span class="text-xs text-gray-500">Comisión {{ number_format(($vendor->pivot->commission_bps ?? 0) / 100, 2) }} %</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Sin comercios todavía.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Puestos de venta</h2>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($outlets as $outlet)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $outlet->name }}</p>
                            <p class="text-xs text-gray-500">{{ $outlet->vendor?->name }}</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $outlet->kind->getLabel() }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Sin puestos: asígnalos desde el perfil de cada comercio.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Editar el evento, incluido su ESTADO: sin esto un festival no se
         puede cerrar ni liquidar desde ninguna pantalla. --}}
    <div id="modal-evento" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('event-panel.events.update', $event) }}"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Editar evento</h3>
                <div class="space-y-3">
                    <div>
                        <label for="ev-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="ev-nombre" name="name" value="{{ old('name', $event->name) }}" required maxlength="255"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </div>
                    <div>
                        <label for="ev-lugar" class="mb-1.5 block text-sm text-gray-700">Lugar</label>
                        <input id="ev-lugar" name="venue" value="{{ old('venue', $event->venue) }}" maxlength="255"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="ev-inicio" class="mb-1.5 block text-sm text-gray-700">Empieza</label>
                            <input id="ev-inicio" name="starts_at" type="datetime-local" required
                                value="{{ old('starts_at', $event->starts_at->format('Y-m-d\TH:i')) }}"
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        </div>
                        <div>
                            <label for="ev-fin" class="mb-1.5 block text-sm text-gray-700">Termina</label>
                            <input id="ev-fin" name="ends_at" type="datetime-local" required
                                value="{{ old('ends_at', $event->ends_at->format('Y-m-d\TH:i')) }}"
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        </div>
                    </div>
                    <div>
                        <label for="ev-estado" class="mb-1.5 block text-sm text-gray-700">Estado</label>
                        <select id="ev-estado" name="status" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            {{-- «Liquidado» NO está aquí: liquidar calcula y congela el
                                 estado de cuenta de cada comercio, y eso ocurre en su
                                 propia pantalla. Ponerlo como rótulo dejaría un evento
                                 marcado como liquidado sin una sola cuenta cerrada. --}}
                            @foreach (\App\Domains\EventManagement\Enums\EventStatus::cases() as $estado)
                                @continue($estado === \App\Domains\EventManagement\Enums\EventStatus::Settled)
                                <option value="{{ $estado->value }}" @selected($event->status === $estado)>{{ $estado->getLabel() }}</option>
                            @endforeach
                            @if ($event->status === \App\Domains\EventManagement\Enums\EventStatus::Settled)
                                <option value="settled" selected>Liquidado</option>
                            @endif
                        </select>
                        <p class="mt-1.5 text-xs text-gray-500">Cerrar exige que no queden cajas abiertas. Para liquidar, entra en la liquidación del evento.</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-evento">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                </div>
            </form>
        </div>
    </div>
@endsection
