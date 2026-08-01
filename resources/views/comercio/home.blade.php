@extends('comercio.layout')

@section('title', $vendor->name)

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">Mi comercio</h1>
        <p class="mt-1 text-sm text-gray-500">Tu menú, tu inventario y tus ventas — todo lo de {{ $vendor->name }} en un solo lugar.</p>
    </div>

    {{-- Pestañas: solo las que el rol puede usar --}}
    @php
        $tabs = ['resumen' => 'Resumen', 'menu' => 'Menú'];
        if ($puede['ventas']) $tabs['ventas'] = 'Ventas';
        $tabs['inventario'] = 'Inventario';
    @endphp
    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-gray-200" role="tablist" aria-orientation="horizontal">
        @foreach ($tabs as $id => $label)
            <button type="button" id="tab-{{ $id }}-item" data-hs-tab="#tab-{{ $id }}" aria-controls="tab-{{ $id }}" role="tab"
                class="{{ $loop->first ? 'active ' : '' }}hs-tab-active:border-sky-600 hs-tab-active:text-sky-600 whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm text-gray-500 hover:text-gray-700">
                {{ $label }}
            </button>
        @endforeach
    </nav>

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
        <p class="text-sm text-gray-500">Usa las pestañas para gestionar el menú{{ $puede['ventas'] ? ', revisar tus ventas' : '' }} y registrar compras de inventario. La caja se opera desde el <a href="/pos" target="_blank" class="font-medium text-sky-700 hover:underline">POS</a>.</p>
    </div>

    @include('vendors.tabs.menu')

    @if ($puede['ventas'])
        @include('vendors.tabs.ventas')
    @endif

    @include('vendors.tabs.inventario')

    @include('vendors.tabs.modales')
@endsection
