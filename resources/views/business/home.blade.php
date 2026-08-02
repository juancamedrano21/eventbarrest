@extends('business.layout')

@section('title', 'Resumen')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $negocio->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">Cómo va el negocio hoy y en los últimos 30 días.</p>
        </div>
        @if ($cajasAbiertas->isNotEmpty())
            <a href="{{ route('business.cash.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm text-teal-800 hover:bg-teal-100">
                <span class="size-2 animate-pulse rounded-full bg-teal-500"></span>
                {{ $cajasAbiertas->count() }} {{ $cajasAbiertas->count() === 1 ? 'caja abierta' : 'cajas abiertas' }}
            </a>
        @endif
    </div>

    @if (! $verDinero)
        <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-500">
            Tu rol no incluye ver los reportes del negocio. Desde el menú lateral puedes llegar a lo que sí te toca.
        </div>
    @else
        {{-- Las cuatro cifras que importan --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas de hoy</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">RD$ {{ number_format($hoy->ventas / 100, 2) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hoy->transacciones }} {{ $hoy->transacciones === 1 ? 'transacción' : 'transacciones' }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas · 30 días</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">RD$ {{ number_format($mes->ventas / 100, 2) }}</p>
                <p class="mt-1 text-xs text-gray-500">Ticket promedio RD$ {{ number_format($ticketPromedio / 100, 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-gray-500">Propina · 30 días</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">RD$ {{ number_format($mes->propina / 100, 2) }}</p>
                {{-- La razón de que exista esta tarjeta: ese dinero no es del
                     negocio, y sumarlo a las ventas inflaría los márgenes. --}}
                <p class="mt-1 text-xs text-gray-500">Del personal, no del negocio</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-gray-500">Devuelto · 30 días</p>
                <p class="mt-1 text-2xl font-semibold {{ $mes->devuelto > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    RD$ {{ number_format($mes->devuelto / 100, 2) }}
                </p>
                {{-- Bruto a propósito: es lo facturado. Las devoluciones se
                     corrigen con notas de crédito, que aún no existen. --}}
                <p class="mt-1 text-xs text-gray-500" title="Lo facturado, antes de notas de crédito">
                    ITBIS facturado RD$ {{ number_format($itbis30 / 100, 2) }}
                </p>
            </div>
        </div>

        {{-- Cómo se reparte lo cobrado --}}
        @if ($mes->cobrado > 0)
            @php
                $porcion = fn (int $parte): float => round($parte * 100 / max(1, $mes->cobrado), 2);
            @endphp
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
                <div class="flex items-baseline justify-between">
                    <p class="text-sm font-medium text-gray-800">De cada peso que pasó por la caja</p>
                    <p class="text-xs text-gray-500">RD$ {{ number_format($mes->cobrado / 100, 2) }} cobrados en 30 días</p>
                </div>
                <div class="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="bg-gray-800" style="width: {{ $porcion($mes->ventas) }}%"></div>
                    <div class="bg-amber-400" style="width: {{ $porcion($mes->propina) }}%"></div>
                    <div class="bg-red-400" style="width: {{ $porcion($mes->devuelto) }}%"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-600">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-gray-800"></span>Venta {{ $porcion($mes->ventas) }}%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-400"></span>Propina {{ $porcion($mes->propina) }}%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-400"></span>Devuelto {{ $porcion($mes->devuelto) }}%</span>
                </div>
            </div>
        @endif

        {{-- Serie de 14 días, sin depender de librerías externas --}}
        @php
            $maximo = max(1, $serie->max('total') ?: 1);
        @endphp
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-sm font-medium text-gray-800">Ventas de los últimos 14 días</p>
            <div class="mt-4 flex h-32 items-end gap-1.5">
                @foreach ($serie as $punto)
                    <div class="group relative flex flex-1 flex-col items-center justify-end">
                        <div class="w-full rounded-t bg-gray-800/85 transition group-hover:bg-gray-900"
                            style="height: {{ max(2, round($punto['total'] * 100 / $maximo)) }}%"
                            title="{{ $punto['dia'] }}: RD$ {{ number_format($punto['total'], 2) }}"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex gap-1.5 text-[10px] text-gray-400">
                @foreach ($serie as $i => $punto)
                    <span class="flex-1 text-center">{{ $i % 2 === 0 ? $punto['dia'] : '' }}</span>
                @endforeach
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Por sucursal --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-3">
                    <p class="text-sm font-medium text-gray-800">Por sucursal · 30 días</p>
                </div>
                @if ($porSucursal->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-500">Todavía no hay ventas.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($porSucursal as $fila)
                                <tr>
                                    <td class="px-5 py-2.5 text-gray-800">{{ $fila->nombre }}</td>
                                    <td class="px-5 py-2.5 text-right text-gray-500">{{ $fila->transacciones }}</td>
                                    <td class="px-5 py-2.5 text-right font-medium text-gray-900">RD$ {{ number_format($fila->ventas / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Lo más vendido --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-3">
                    <p class="text-sm font-medium text-gray-800">Lo más vendido · 30 días</p>
                </div>
                @if ($topProductos->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-500">Todavía no hay ventas.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($topProductos as $fila)
                                <tr>
                                    <td class="px-5 py-2.5 text-gray-800">{{ $fila->nombre }}</td>
                                    <td class="px-5 py-2.5 text-right text-gray-500">{{ (int) $fila->unidades }} u.</td>
                                    <td class="px-5 py-2.5 text-right font-medium text-gray-900">RD$ {{ number_format($fila->importe / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Cómo pagan --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-3">
                    <p class="text-sm font-medium text-gray-800">Cómo pagan · 30 días</p>
                </div>
                @if ($porMetodo->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-500">Todavía no hay cobros.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($porMetodo as $fila)
                                <tr>
                                    <td class="px-5 py-2.5 text-gray-800">
                                        {{ \App\Domains\Sales\Enums\PaymentMethod::from($fila->method)->getLabel() }}
                                    </td>
                                    <td class="px-5 py-2.5 text-right text-gray-500">{{ (int) $fila->veces }}</td>
                                    <td class="px-5 py-2.5 text-right font-medium text-gray-900">RD$ {{ number_format($fila->total / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Lo que hay que reponer --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <p class="text-sm font-medium text-gray-800">Bajo mínimo</p>
                    <a href="{{ route('business.inventory') }}" class="text-xs text-gray-500 hover:text-gray-800">Ver inventario</a>
                </div>
                @if ($bajoMinimo->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-500">Nada por reponer.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($bajoMinimo as $nivel)
                                <tr>
                                    <td class="px-5 py-2.5 text-gray-800">{{ $nivel->inventoryItem?->name }}</td>
                                    <td class="px-5 py-2.5 text-gray-500">{{ $nivel->operatingUnit?->name }}</td>
                                    <td class="px-5 py-2.5 text-right font-medium text-amber-600">
                                        {{ rtrim(rtrim(number_format((float) $nivel->quantity, 3), '0'), '.') }}
                                        {{ $nivel->inventoryItem?->base_unit?->value }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif

    {{-- Con qué factura el negocio --}}
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-sm font-medium text-gray-800">ITBIS: {{ $modoVigente->getLabel() }}</p>
                <p class="mt-0.5 text-sm text-gray-500">{{ $modoVigente->description() }}</p>
            </div>
            @can('fiscal.manage')
                <a href="{{ route('business.settings.edit') }}" class="text-sm text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-gray-900">Cambiar</a>
            @endcan
        </div>
    </div>
@endsection
