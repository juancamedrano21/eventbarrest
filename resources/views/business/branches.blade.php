@extends('business.layout')

@section('title', 'Sucursales')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Sucursales</h1>
            <p class="mt-1 text-sm text-gray-500">Los locales donde se vende. Cada uno tiene su caja y su inventario.</p>
        </div>
        <button type="button" data-hs-overlay="#modal-sucursal" aria-haspopup="dialog"
            class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Nueva sucursal
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if ($sucursales->isEmpty())
            <div class="px-5 py-10 text-center">
                <p class="text-sm text-gray-500">Todavía no hay sucursales.</p>
                <p class="mt-1 text-sm text-gray-400">Crea la primera para poder abrir caja y vender.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3 font-medium">Sucursal</th>
                        <th class="px-5 py-3 font-medium">Despacha</th>
                        <th class="px-5 py-3 font-medium">Estado</th>
                        <th class="px-5 py-3 font-medium">Alta</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sucursales as $sucursal)
                        <tr>
                            <td class="px-5 py-3 font-medium text-gray-900">
                                {{ $sucursal->name }}
                                @if (in_array($sucursal->id, $cajasAbiertas, true))
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-teal-50 px-2 py-0.5 text-xs text-teal-700">
                                        <span class="size-1.5 rounded-full bg-teal-500"></span>Caja abierta
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-gray-600">{{ $sucursal->kind->getLabel() }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $sucursal->status->value === 'active' ? 'bg-teal-50 text-teal-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $sucursal->status->getLabel() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $sucursal->created_at?->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 text-right">
                                <button type="button" data-hs-overlay="#modal-sucursal-{{ $sucursal->id }}" aria-haspopup="dialog"
                                    class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Una sucursal nunca se borra: sus ventas, arqueos y movimientos de inventario la referencian para siempre. Cuando deja de operar, se cierra.
    </p>

    {{-- Alta --}}
    <div id="modal-sucursal" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.branches.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="font-medium text-gray-900">Nueva sucursal</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="sucursal-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="sucursal-nombre" name="name" value="{{ old('name') }}" required maxlength="255"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="sucursal-tipo" class="mb-1.5 block text-sm text-gray-700">Qué despacha</label>
                        <select id="sucursal-tipo" name="kind" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            @foreach ($tipos as $tipo)
                                <option value="{{ $tipo->value }}" @selected(old('kind', 'mixed') === $tipo->value)>{{ $tipo->getLabel() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-gray-500">Decide qué parte del menú ve el POS y por qué impresora salen las comandas.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-sucursal" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Crear</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edición, una por sucursal --}}
    @foreach ($sucursales as $sucursal)
        <div id="modal-sucursal-{{ $sucursal->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-lg">
                <form method="POST" action="{{ route('business.branches.update', $sucursal) }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                    @csrf
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h3 class="font-medium text-gray-900">{{ $sucursal->name }}</h3>
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label for="nombre-{{ $sucursal->id }}" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                            <input id="nombre-{{ $sucursal->id }}" name="name" value="{{ $sucursal->name }}" required maxlength="255"
                                class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label for="tipo-{{ $sucursal->id }}" class="mb-1.5 block text-sm text-gray-700">Qué despacha</label>
                            <select id="tipo-{{ $sucursal->id }}" name="kind" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($tipos as $tipo)
                                    <option value="{{ $tipo->value }}" @selected($sucursal->kind === $tipo)>{{ $tipo->getLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="estado-{{ $sucursal->id }}" class="mb-1.5 block text-sm text-gray-700">Estado</label>
                            <select id="estado-{{ $sucursal->id }}" name="status" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($estados as $estado)
                                    <option value="{{ $estado->value }}" @selected($sucursal->status === $estado)>{{ $estado->getLabel() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-gray-500">Cerrarla impide abrir caja y vender allí. El histórico se conserva.</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                        <button type="button" data-hs-overlay="#modal-sucursal-{{ $sucursal->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                        <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection
