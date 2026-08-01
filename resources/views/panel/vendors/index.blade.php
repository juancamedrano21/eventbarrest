@extends('panel.layout')

@section('title', 'Negocios')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white">Negocios</h1>
            <p class="mt-1 text-sm text-neutral-400">Los comercios que venden en tus eventos: cada uno con su equipo, su catálogo y su caja.</p>
        </div>
        <button type="button" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-comercio" data-hs-overlay="#modal-comercio">
            Nuevo negocio
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                    <th class="px-5 py-3">Negocio</th>
                    <th class="px-5 py-3">Contacto</th>
                    <th class="px-5 py-3 text-center">Eventos</th>
                    <th class="px-5 py-3">Estado</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800">
                @forelse ($vendors as $vendor)
                    <tr class="hover:bg-neutral-800/40">
                        <td class="px-5 py-3">
                            <a href="{{ route('panel.vendors.show', $vendor) }}" class="font-medium text-white hover:text-sky-400">{{ $vendor->name }}</a>
                            <p class="text-xs text-neutral-500">{{ $vendor->rnc ?? 'Sin RNC' }}</p>
                        </td>
                        <td class="px-5 py-3 text-neutral-400">{{ $vendor->contact_name ?? '—' }}</td>
                        <td class="px-5 py-3 text-center text-neutral-300">{{ $vendor->events_count }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs
                                {{ $vendor->status->value === 'active' ? 'bg-teal-950 text-teal-300' : 'bg-amber-950 text-amber-300' }}">
                                {{ $vendor->status->getLabel() }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a href="{{ route('panel.vendors.show', $vendor) }}" class="text-sm text-sky-400 hover:text-sky-300">Entrar →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-neutral-500">Aún no hay negocios: da de alta el primero.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: nuevo negocio --}}
    <div id="modal-comercio" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.store') }}" class="rounded-xl border border-neutral-700 bg-neutral-900 p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-white">Nuevo negocio</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del negocio" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="rnc" value="{{ old('rnc') }}" placeholder="RNC / Cédula (suyo, no del organizador)" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="contact_name" value="{{ old('contact_name') }}" placeholder="Persona de contacto" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="contact_phone" value="{{ old('contact_phone') }}" placeholder="Teléfono" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300" data-hs-overlay="#modal-comercio">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear negocio</button>
                </div>
            </form>
        </div>
    </div>
@endsection
