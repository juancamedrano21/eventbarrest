@extends('panel.layout')

@section('title', 'Eventos')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white">Eventos</h1>
            <p class="mt-1 text-sm text-neutral-400">Tus festivales y producciones: cada uno con sus comercios y sus puestos.</p>
        </div>
        <button type="button" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-evento" data-hs-overlay="#modal-evento">
            Nuevo evento
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                    <th class="px-5 py-3">Evento</th>
                    <th class="px-5 py-3">Fechas</th>
                    <th class="px-5 py-3 text-center">Comercios</th>
                    <th class="px-5 py-3">Estado</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800">
                @forelse ($events as $event)
                    <tr class="hover:bg-neutral-800/40">
                        <td class="px-5 py-3">
                            <a href="{{ route('panel.events.show', $event) }}" class="font-medium text-white hover:text-sky-400">{{ $event->name }}</a>
                            <p class="text-xs text-neutral-500">{{ $event->venue ?? '—' }}</p>
                        </td>
                        <td class="px-5 py-3 text-neutral-400">{{ $event->starts_at->format('d M Y') }} → {{ $event->ends_at->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-center text-neutral-300">{{ $event->vendors_count }}</td>
                        <td class="px-5 py-3"><span class="rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs text-neutral-300">{{ $event->status->getLabel() }}</span></td>
                        <td class="px-5 py-3 text-right">
                            <a href="{{ route('panel.events.show', $event) }}" class="text-sm text-sky-400 hover:text-sky-300">Ver →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-neutral-500">Aún no hay eventos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: nuevo evento --}}
    <div id="modal-evento" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.events.store') }}" class="rounded-xl border border-neutral-700 bg-neutral-900 p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-white">Nuevo evento</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del evento" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="venue" value="{{ old('venue') }}" placeholder="Lugar" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <label class="block text-xs text-neutral-500">Inicio
                        <input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" required class="mt-1 w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                    </label>
                    <label class="block text-xs text-neutral-500">Fin
                        <input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" required class="mt-1 w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                    </label>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300" data-hs-overlay="#modal-evento">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear evento</button>
                </div>
            </form>
        </div>
    </div>
@endsection
