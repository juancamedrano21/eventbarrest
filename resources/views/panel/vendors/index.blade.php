@extends('panel.layout')

@section('title', 'Negocios')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Negocios</h1>
            <p class="mt-1 text-sm text-gray-500">Los comercios que venden en tus eventos: cada uno con su equipo, su catálogo y su caja.</p>
        </div>
        <button type="button" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-comercio" data-hs-overlay="#modal-comercio">
            Nuevo negocio
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-5 py-3">Negocio</th>
                    <th class="px-5 py-3">Contacto</th>
                    <th class="px-5 py-3 text-center">Eventos</th>
                    <th class="px-5 py-3">Estado</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($vendors as $vendor)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <a href="{{ route('panel.vendors.show', $vendor) }}" class="font-medium text-gray-800 hover:text-sky-600">{{ $vendor->name }}</a>
                            <p class="text-xs text-gray-500">{{ $vendor->rnc ?? 'Sin RNC' }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-500">{{ $vendor->contact_name ?? '—' }}</td>
                        <td class="px-5 py-3 text-center text-gray-600">{{ $vendor->events_count }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs
                                {{ $vendor->status->value === 'active' ? 'bg-teal-100 text-teal-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $vendor->status->getLabel() }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a href="{{ route('panel.vendors.show', $vendor) }}" class="text-sm text-sky-600 hover:text-sky-700">Entrar →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">Aún no hay negocios: da de alta el primero.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: nuevo negocio --}}
    <div id="modal-comercio" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.store') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo negocio</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del negocio" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="rnc" value="{{ old('rnc') }}" placeholder="RNC / Cédula (suyo, no del organizador)" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="contact_name" value="{{ old('contact_name') }}" placeholder="Persona de contacto" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="contact_phone" value="{{ old('contact_phone') }}" placeholder="Teléfono" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-comercio">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear negocio</button>
                </div>
            </form>
        </div>
    </div>
@endsection
