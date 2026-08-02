@extends('business.layout')

@section('title', 'Inventario')

@section('content')
    @php
        // Siempre en bloque: la forma corta de esta misma directiva se
        // empareja con el primer cierre del archivo y se traga lo de en medio.
        $cantidad = fn ($n): string => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
        $moneda = fn (int $cents): string => 'RD$ '.number_format($cents / 100, 2);
        $bajoMinimo = $existencias->filter->isLow()->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Inventario</h1>
            <p class="mt-1 text-sm text-gray-500">Los insumos son del negocio; las existencias, de cada sucursal.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" data-hs-overlay="#modal-insumo" aria-haspopup="dialog"
                class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm text-gray-700 hover:bg-gray-50">Nuevo insumo</button>
            <button type="button" data-hs-overlay="#modal-compra" aria-haspopup="dialog" @disabled($insumos->isEmpty() || $sucursales->isEmpty())
                class="rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-40">Registrar compra</button>
        </div>
    </div>

    {{-- Filtro por sucursal --}}
    @if ($sucursales->count() > 1)
        <form method="GET" class="mb-5 flex flex-wrap items-end gap-2">
            <div>
                <label for="filtro-sucursal" class="mb-1 block text-xs text-gray-600">Sucursal</label>
                <select id="filtro-sucursal" name="sucursal" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                    <option value="">Todas</option>
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}" @selected($sucursalFiltrada === $sucursal->id)>{{ $sucursal->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    @endif

    <nav class="mb-5 flex gap-1 border-b border-gray-200" role="tablist" aria-label="Inventario">
        <button type="button" class="active hs-tab-active:border-gray-900 hs-tab-active:text-gray-900 -mb-px border-b-2 border-transparent px-4 py-2.5 text-sm text-gray-500 hover:text-gray-800"
            id="tab-existencias-item" data-hs-tab="#tab-existencias" aria-controls="tab-existencias" role="tab">
            Existencias
            @if ($bajoMinimo > 0)
                <span class="ml-1.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">{{ $bajoMinimo }}</span>
            @endif
        </button>
        <button type="button" class="hs-tab-active:border-gray-900 hs-tab-active:text-gray-900 -mb-px border-b-2 border-transparent px-4 py-2.5 text-sm text-gray-500 hover:text-gray-800"
            id="tab-insumos-item" data-hs-tab="#tab-insumos" aria-controls="tab-insumos" role="tab">Insumos</button>
        <button type="button" class="hs-tab-active:border-gray-900 hs-tab-active:text-gray-900 -mb-px border-b-2 border-transparent px-4 py-2.5 text-sm text-gray-500 hover:text-gray-800"
            id="tab-movimientos-item" data-hs-tab="#tab-movimientos" aria-controls="tab-movimientos" role="tab">Movimientos</button>
    </nav>

    {{-- Existencias --}}
    <div id="tab-existencias" role="tabpanel" aria-labelledby="tab-existencias-item">
        @if ($puede['ajustar'] || $puede['trasladar'])
            <div class="mb-4 flex flex-wrap gap-2">
                @if ($puede['ajustar'])
                    <button type="button" data-hs-overlay="#modal-ajuste" aria-haspopup="dialog"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Ajuste de conteo</button>
                    <button type="button" data-hs-overlay="#modal-merma" aria-haspopup="dialog"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Merma</button>
                @endif
                @if ($puede['trasladar'] && $sucursales->count() > 1)
                    <button type="button" data-hs-overlay="#modal-traslado" aria-haspopup="dialog"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Traslado entre sucursales</button>
                @endif
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            @if ($existencias->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500">Sin existencias todavía. Registra una compra para empezar.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Sucursal</th>
                            <th class="px-5 py-3 font-medium">Insumo</th>
                            <th class="px-5 py-3 text-right font-medium">Existencia</th>
                            <th class="px-5 py-3 text-right font-medium">Umbral</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($existencias as $nivel)
                            <tr>
                                <td class="px-5 py-3 text-gray-600">{{ $nivel->operatingUnit?->name }}</td>
                                <td class="px-5 py-3 font-medium text-gray-900">{{ $nivel->inventoryItem?->name }}</td>
                                <td class="px-5 py-3 text-right {{ $nivel->quantity < 0 ? 'text-red-600' : ($nivel->isLow() ? 'text-amber-600' : 'text-gray-900') }}">
                                    {{ $cantidad($nivel->quantity) }} {{ $nivel->inventoryItem?->base_unit?->value }}
                                    @if ($nivel->isLow())
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">Bajo mínimo</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-gray-500">{{ $cantidad($nivel->alert_threshold) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button type="button" data-hs-overlay="#modal-umbral-{{ $nivel->id }}" aria-haspopup="dialog"
                                        class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Umbral</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">El stock puede quedar negativo a propósito: el POS nunca bloquea una venta por un conteo desfasado.</p>
    </div>

    {{-- Insumos --}}
    <div id="tab-insumos" class="hidden" role="tabpanel" aria-labelledby="tab-insumos-item">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            @if ($insumos->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500">Sin insumos. Créalos para poder comprar y armar recetas.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Insumo</th>
                            <th class="px-5 py-3 font-medium">Unidad</th>
                            <th class="px-5 py-3 text-right font-medium">Costo por unidad</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($insumos as $insumo)
                            <tr>
                                <td class="px-5 py-3 font-medium text-gray-900">{{ $insumo->name }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $insumo->base_unit->getLabel() }}</td>
                                <td class="px-5 py-3 text-right text-gray-900">{{ $moneda($insumo->cost_cents) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button type="button" data-hs-overlay="#modal-insumo-{{ $insumo->id }}" aria-haspopup="dialog"
                                        class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">Cada compra recalcula el costo promedio ponderado. La unidad base no se cambia: recetas y movimientos ya hablan en ella.</p>
    </div>

    {{-- Libro mayor --}}
    <div id="tab-movimientos" class="hidden" role="tabpanel" aria-labelledby="tab-movimientos-item">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            @if ($movimientos->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500">Todavía no hay movimientos.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3 font-medium">Fecha</th>
                                <th class="px-5 py-3 font-medium">Tipo</th>
                                <th class="px-5 py-3 font-medium">Sucursal</th>
                                <th class="px-5 py-3 font-medium">Insumo</th>
                                <th class="px-5 py-3 text-right font-medium">Cantidad</th>
                                <th class="px-5 py-3 font-medium">Registró</th>
                                <th class="px-5 py-3 font-medium">Referencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($movimientos as $movimiento)
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-gray-500">
                                        {{ $movimiento->created_at?->timezone(config('app.business_timezone'))->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-5 py-2.5">
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $movimiento->type->getLabel() }}</span>
                                    </td>
                                    <td class="px-5 py-2.5 text-gray-600">{{ $movimiento->operatingUnit?->name }}</td>
                                    <td class="px-5 py-2.5 text-gray-800">{{ $movimiento->inventoryItem?->name }}</td>
                                    <td class="px-5 py-2.5 text-right font-medium {{ $movimiento->quantity < 0 ? 'text-red-600' : 'text-teal-700' }}">
                                        {{ $movimiento->quantity > 0 ? '+' : '' }}{{ $cantidad($movimiento->quantity) }}
                                    </td>
                                    <td class="px-5 py-2.5 text-gray-500">{{ $movimiento->user?->name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-gray-500">{{ Str::limit((string) $movimiento->reference, 30) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">El libro mayor es inmutable: una corrección es un movimiento nuevo, nunca la edición de uno viejo.</p>
    </div>

    @include('business.partials.inventory-modals')
@endsection
