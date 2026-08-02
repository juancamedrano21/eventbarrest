@extends('event-vendor.layout')

@section('title', $vendor->name)

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">Mi comercio</h1>
        <p class="mt-1 text-sm text-gray-500">Tu menú, tu inventario y tus ventas — todo lo de {{ $vendor->name }} en un solo lugar.</p>
    </div>

    {{-- Pestañas: solo las que el rol puede usar --}}
    @php
        $bajoMinimo = $stockLevels->filter->isLow()->count();
        $tabs = [
            'resumen' => ['label' => 'Resumen', 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
            'menu' => ['label' => 'Menú', 'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25', 'badge' => $menuCategories->sum(fn ($c) => $c->products->count())],
        ];

        if ($puede['ventas']) {
            $tabs['ventas'] = ['label' => 'Ventas', 'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z', 'badge' => $recentOrders->count()];
        }

        $tabs['inventario'] = ['label' => 'Inventario', 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z', 'badge' => $bajoMinimo > 0 ? $bajoMinimo : null, 'tono' => 'alerta'];
    @endphp

    @include('vendors.tabs.nav', ['tabs' => $tabs])

    {{-- Tab: Resumen --}}
    <div id="tab-resumen" role="tabpanel" aria-labelledby="tab-resumen-item">
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            @if ($puede['ventas'])
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Ventas de hoy</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesToday / 100, 2) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Ventas históricas</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesTotal / 100, 2) }}</p>
                </div>
            @endif
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Insumos bajo mínimo</p>
                <p class="mt-1 text-2xl font-semibold {{ $stockLevels->filter->isLow()->isEmpty() ? 'text-gray-800' : 'text-red-600' }}">
                    {{ $stockLevels->filter->isLow()->count() }}
                </p>
            </div>
        </div>
        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Fiscalidad de tu comercio</p>
            <p class="mt-1 text-sm text-gray-800">
                <span class="font-medium">ITBIS {{ $modoVigente->getLabel() }}.</span>
                {{ $modoVigente->description() }}
            </p>
            <p class="mt-1 text-xs text-gray-500">La fija el organizador del evento; carga tus precios de acuerdo con ella.</p>
        </div>

        <p class="text-sm text-gray-500">Usa las pestañas para gestionar el menú{{ $puede['ventas'] ? ', revisar tus ventas' : '' }} y registrar compras de inventario. La caja se opera desde el <a href="/pos" target="_blank" class="font-medium text-sky-700 hover:underline">POS</a>.</p>
    </div>

    @include('vendors.tabs.menu')

    @if ($puede['ventas'])
        @include('vendors.tabs.ventas')
    @endif

    @include('vendors.tabs.inventario')

    @include('vendors.tabs.modales')

    @include('vendors.tabs.persistencia')
@endsection
