@extends($panelLayout)

@section('title', $event->name)

@section('content')
    <div class="mb-8">
        <p class="text-xs uppercase tracking-widest text-gray-500">Evento</p>
        <h1 class="mt-1 text-2xl font-semibold text-gray-800">{{ $event->name }}</h1>
        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
            <span>{{ $event->starts_at->format('d M Y, H:i') }} → {{ $event->ends_at->format('d M Y, H:i') }}</span>
            <span>{{ $event->venue ?? '—' }}</span>
            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $event->status->getLabel() }}</span>
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
                        <a href="{{ route('panel.vendors.show', $vendor) }}" class="text-gray-800 hover:text-sky-600">{{ $vendor->name }}</a>
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
@endsection
