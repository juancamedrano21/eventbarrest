@extends('panel.layout')

@section('title', $event->name)

@section('content')
    <div class="mb-8">
        <p class="text-xs uppercase tracking-widest text-neutral-500">Evento</p>
        <h1 class="mt-1 text-2xl font-semibold text-white">{{ $event->name }}</h1>
        <div class="mt-2 flex flex-wrap gap-4 text-sm text-neutral-400">
            <span>{{ $event->starts_at->format('d M Y, H:i') }} → {{ $event->ends_at->format('d M Y, H:i') }}</span>
            <span>{{ $event->venue ?? '—' }}</span>
            <span class="rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs text-neutral-300">{{ $event->status->getLabel() }}</span>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Comercios participantes <span class="ml-1 text-xs font-normal text-neutral-500">se invitan desde el perfil de cada comercio</span></h2>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($participants as $vendor)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <a href="{{ route('panel.vendors.show', $vendor) }}" class="text-neutral-200 hover:text-sky-400">{{ $vendor->name }}</a>
                        <span class="text-xs text-neutral-400">Comisión {{ number_format(($vendor->pivot->commission_bps ?? 0) / 100, 2) }} %</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Sin comercios todavía.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Puestos de venta</h2>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($outlets as $outlet)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-neutral-200">{{ $outlet->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $outlet->vendor?->name }}</p>
                        </div>
                        <span class="rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs text-neutral-300">{{ $outlet->kind->getLabel() }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Sin puestos: asígnalos desde el perfil de cada comercio.</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
