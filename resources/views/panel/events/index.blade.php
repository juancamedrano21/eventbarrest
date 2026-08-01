@extends('panel.layout')

@section('title', 'Eventos')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Eventos</h1>
            <p class="mt-1 text-sm text-gray-500">Tus festivales y producciones: cada uno con sus comercios y sus puestos.</p>
        </div>
        <button type="button" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-evento" data-hs-overlay="#modal-evento">
            Nuevo evento
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-5 py-3">Evento</th>
                    <th class="px-5 py-3">Fechas</th>
                    <th class="px-5 py-3 text-center">Comercios</th>
                    <th class="px-5 py-3">Estado</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($events as $event)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <a href="{{ route('panel.events.show', $event) }}" class="font-medium text-gray-800 hover:text-sky-600">{{ $event->name }}</a>
                            <p class="text-xs text-gray-500">{{ $event->venue ?? '—' }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-500">{{ $event->starts_at->format('d M Y') }} → {{ $event->ends_at->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-center text-gray-600">{{ $event->vendors_count }}</td>
                        <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $event->status->getLabel() }}</span></td>
                        <td class="px-5 py-3 text-right">
                            <a href="{{ route('panel.events.show', $event) }}" class="text-sm text-sky-600 hover:text-sky-700">Ver →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">Aún no hay eventos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: nuevo evento --}}
    <div id="modal-evento" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.events.store') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo evento</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del evento" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="venue" value="{{ old('venue') }}" placeholder="Lugar" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <label class="block text-xs text-gray-500">Inicio
                        <input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" required class="mt-1 w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </label>
                    <label class="block text-xs text-gray-500">Fin
                        <input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" required class="mt-1 w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </label>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-evento">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear evento</button>
                </div>
            </form>
        </div>
    </div>
@endsection
