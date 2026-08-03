@extends($panelLayout)

@section('title', 'Tiempos — '.$event->name)

@section('content')
    @php
        $enlace = fn (string $cual): string => route('event-panel.events.timings', [$event, 'rango' => $cual]);

        $pestanas = [
            'hoy' => 'Hoy',
            'evento' => 'El evento entero',
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('event-panel.events.show', $event) }}" class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-800">
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $event->name }}
            </a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">Tiempos de despacho</h1>
            <p class="mt-1 text-sm text-gray-500">
                Cuánto esperó la gente y en qué se le fue esa espera. La liquidación cuenta el dinero; esto, la fila.
            </p>
        </div>

        <div class="flex items-center gap-2">
            {{-- El día se corta en hora del país, no en UTC: la noche del
                 sábado es del sábado aunque en Londres ya sea domingo. --}}
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
                @foreach ($pestanas as $cual => $texto)
                    <a href="{{ $enlace($cual) }}"
                        class="rounded-md px-3 py-1.5 text-sm {{ $rango === $cual ? 'bg-sky-600 font-medium text-white' : 'text-gray-600 hover:bg-gray-50' }}">
                        {{ $texto }}
                    </a>
                @endforeach
            </div>
            <a href="{{ route('event-panel.events.settlement', $event) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Ver el dinero</a>
        </div>
    </div>

    <p class="mb-5 text-xs text-gray-500">
        Ventana: del {{ $informe->from->timezone($tz)->format('d/m/Y, H:i') }}
        al {{ $informe->to->timezone($tz)->format('d/m/Y, H:i') }} (hora del país).
        Se corta por cuándo se cobró la venta y no por cuándo salió el plato, para que las comandas servidas pasada la
        medianoche sigan siendo de la noche que las pidió.
    </p>

    @include('event-panel.events.partials.tiempos', [
        'informe' => $informe,
        // El organizador compara comercios entre sí: aquí el nombre de cada
        // uno es la mitad de la tabla.
        'comparaComercios' => true,
        // Y el salto de lo que ya pasó a lo que está pasando ahora, con el
        // evento que se está mirando ya puesto.
        'enlaceEnVivo' => route('event-panel.comandas', ['evento' => $event->id]),
    ])
@endsection
