@extends('event-vendor.layout')

@section('title', 'Tiempos')

@section('content')
    @php
        // La fecha se guarda en UTC pero se lee en hora de RD: la noche del
        // sábado es del sábado aunque el servidor ya esté en domingo.
        $enRd = fn ($momento) => $momento->copy()->setTimezone($tz);

        $tonos = [
            'sky' => 'border-sky-200 bg-sky-50 text-sky-900',
            'ambar' => 'border-amber-200 bg-amber-50 text-amber-900',
            'gris' => 'border-gray-200 bg-gray-50 text-gray-700',
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('event-vendor.home') }}" class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-800">
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $vendor->name }}
            </a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">Tiempos de despacho</h1>
            <p class="mt-1 text-sm text-gray-500">
                Cuánto esperó de verdad quien te compró {{ $esHoy ? 'hoy' : 'el '.$enRd($dia)->format('d/m/Y') }}, y en qué se le fue esa espera.
                Son tus puestos y nada más: aquí no aparece ni una comanda de otro comercio del evento.
            </p>
        </div>

        {{-- Casi siempre se mira hoy; de vez en cuando la noche de ayer con la
             cabeza fría, que es cuando se decide algo. --}}
        <div class="flex items-center gap-2">
            <a href="{{ $urlDe($dia->copy()->subDay()) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Día anterior</a>
            @unless ($esHoy)
                <a href="{{ $urlDe($dia->copy()->addDay()) }}"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Día siguiente</a>
                <a href="{{ route('event-vendor.timings') }}"
                    class="rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sky-500">Hoy</a>
            @endunless
        </div>
    </div>

    @if ($informe->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-5 py-12 text-center">
            <p class="text-sm text-gray-500">
                {{ $esHoy ? 'Todavía no has cobrado nada hoy.' : 'No hubo ventas en tus puestos ese día.' }}
            </p>
            <p class="mt-1 text-sm text-gray-400">En cuanto empieces a cobrar, aquí sabrás cuánto está esperando tu gente y por qué.</p>
        </div>
    @else
        {{-- Esta tarjeta no repite el diagnóstico —de eso ya se encarga el
             parcial, que explica en qué se reparte la espera—: dice qué hacer.
             Es la única diferencia real entre esta pantalla y la del
             organizador, y es la que la justifica. --}}
        @if ($consejo)
            <div class="mb-6 rounded-xl border px-5 py-4 {{ $tonos[$consejo['tono']] ?? $tonos['gris'] }}">
                <p class="text-xs uppercase tracking-wide opacity-70">Qué hacer con esto</p>
                <p class="mt-1 font-medium">{{ $consejo['titulo'] }}</p>
                <p class="mt-1 text-sm">{{ $consejo['texto'] }}</p>
            </div>
        @endif

        {{-- El MISMO parcial que el organizador ve en /event-panel, sin
             columna de comercio porque aquí todo es del mismo. Copiarlo para
             cambiarle dos columnas sería garantizar que dentro de un mes el
             organizador y el comercio lean cifras distintas del mismo puesto,
             y esa es la peor forma de perder la confianza en un dato que los
             dos usan para hablar entre ellos. --}}
        @include('event-panel.events.partials.tiempos', [
            'informe' => $informe,
            'comparaComercios' => false,
        ])

        <p class="mt-4 text-xs text-gray-500">
            La espera no es la suma de la cola y la preparación: lleva dentro lo que tardó el POS en avisar al servidor,
            y por eso se enseña aparte. Las cifras son medianas y p90 —«una de cada diez esperó más que esto»—, nunca promedios:
            una comanda que alguien olvidó marcar arruinaría un promedio y no diría nada de tu noche.
        </p>
    @endif
@endsection
