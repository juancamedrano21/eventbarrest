@extends('business.layout')

@section('title', 'Ventas')

@section('content')
    @php
        // Siempre en bloque: la forma corta de esta misma directiva se
        // empareja con el primer cierre del archivo y se traga lo de en medio.
        $moneda = fn (int $cents): string => 'RD$ '.number_format($cents / 100, 2);
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Ventas</h1>
        <p class="mt-1 text-sm text-gray-500">Cada venta cobrada, con su detalle. Ninguna se edita ni se borra.</p>
    </div>

    {{-- Filtros --}}
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4">
        <div>
            <label for="f-sucursal" class="mb-1 block text-xs text-gray-600">Sucursal</label>
            <select id="f-sucursal" name="sucursal" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                <option value="">Todas</option>
                @foreach ($sucursales as $sucursal)
                    <option value="{{ $sucursal->id }}" @selected($sucursalFiltrada === $sucursal->id)>{{ $sucursal->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-desde" class="mb-1 block text-xs text-gray-600">Desde</label>
            <input id="f-desde" name="desde" type="date" value="{{ $desde }}"
                class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        <div>
            <label for="f-hasta" class="mb-1 block text-xs text-gray-600">Hasta</label>
            <input id="f-hasta" name="hasta" type="date" value="{{ $hasta }}"
                class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        <button type="submit" class="rounded-lg bg-gray-900 px-3.5 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Filtrar</button>
        @if ($sucursalFiltrada || $desde || $hasta)
            <a href="{{ route('business.sales.index') }}" class="py-1.5 text-sm text-gray-500 hover:text-gray-800">Limpiar</a>
        @endif
    </form>

    {{-- Lo que suman las ventas del filtro --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Ventas</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">{{ $moneda($resumen->ventas) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Propina</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">{{ $moneda($resumen->propina) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Devuelto</p>
            <p class="mt-1 text-xl font-semibold {{ $resumen->devuelto > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $moneda($resumen->devuelto) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Transacciones</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">{{ $resumen->transacciones }}</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if ($ordenes->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-gray-500">No hay ventas con ese filtro.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Orden</th>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Sucursal</th>
                            <th class="px-5 py-3 font-medium">Cobró</th>
                            <th class="px-5 py-3 text-right font-medium">Propina</th>
                            <th class="px-5 py-3 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($ordenes as $orden)
                            <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('business.sales.show', $orden) }}'">
                                <td class="px-5 py-3">
                                    <a href="{{ route('business.sales.show', $orden) }}" class="font-mono text-gray-900 hover:underline">{{ $orden->publicNumber() }}</a>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">
                                    {{ $orden->paid_at?->timezone($tz)->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $orden->operatingUnit?->name }}</td>
                                <td class="px-5 py-3 text-gray-600">
                                    {{ $orden->user?->name ?? '—' }}
                                    @if ($orden->customer_name)
                                        <span class="block text-xs text-gray-400">para {{ $orden->customer_name }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-gray-500">
                                    {{ $orden->tip_cents > 0 ? $moneda($orden->tip_cents) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ $moneda($orden->total_cents) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($ordenes->hasPages())
        <div class="mt-4">{{ $ordenes->links() }}</div>
    @endif
@endsection
