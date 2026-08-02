@extends('business.layout')

@section('title', 'Caja')

@section('content')
    @php
        // Siempre en bloque: la forma corta de esta misma directiva se
        // empareja con el primer cierre del archivo y se traga lo de en medio.
        $moneda = fn (int $cents): string => 'RD$ '.number_format($cents / 100, 2);
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Caja</h1>
            <p class="mt-1 text-sm text-gray-500">Los arqueos de cada turno: lo que debía haber, lo que se contó y la diferencia.</p>
        </div>
        @if ($abiertas > 0)
            <span class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm text-teal-800">
                <span class="size-2 animate-pulse rounded-full bg-teal-500"></span>
                {{ $abiertas }} {{ $abiertas === 1 ? 'caja abierta' : 'cajas abiertas' }}
            </span>
        @endif
    </div>

    @if ($sucursales->count() > 1)
        <form method="GET" class="mb-5">
            <label for="c-sucursal" class="mb-1 block text-xs text-gray-600">Sucursal</label>
            <select id="c-sucursal" name="sucursal" onchange="this.form.submit()"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                <option value="">Todas</option>
                @foreach ($sucursales as $sucursal)
                    <option value="{{ $sucursal->id }}" @selected($sucursalFiltrada === $sucursal->id)>{{ $sucursal->name }}</option>
                @endforeach
            </select>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if ($sesiones->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-gray-500">Todavía no se ha abierto ninguna caja.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Turno</th>
                            <th class="px-5 py-3 font-medium">Sucursal</th>
                            <th class="px-5 py-3 font-medium">Cajero</th>
                            <th class="px-5 py-3 text-right font-medium">Fondo</th>
                            <th class="px-5 py-3 text-right font-medium">Efectivo</th>
                            <th class="px-5 py-3 text-right font-medium">Esperado</th>
                            <th class="px-5 py-3 text-right font-medium">Contado</th>
                            <th class="px-5 py-3 text-right font-medium">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sesiones as $sesion)
                            <tr class="{{ $sesion->isOpen() ? 'bg-teal-50/40' : '' }}">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="text-gray-900">{{ $sesion->opened_at?->timezone($tz)->format('d/m/Y H:i') }}</span>
                                    @if ($sesion->isOpen())
                                        <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-teal-100 px-1.5 py-0.5 text-xs text-teal-800">
                                            <span class="size-1.5 rounded-full bg-teal-500"></span>Abierta
                                        </span>
                                    @else
                                        <span class="block text-xs text-gray-400">
                                            cerró {{ $sesion->closed_at?->timezone($tz)->format('H:i') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $sesion->operatingUnit?->name }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $sesion->user?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right text-gray-500">{{ $moneda((int) $sesion->opening_cents) }}</td>
                                <td class="px-5 py-3 text-right text-gray-500">
                                    {{ $moneda((int) $sesion->getAttribute('efectivo_cobrado')) }}
                                    @if ($sesion->getAttribute('efectivo_devuelto') > 0)
                                        <span class="block text-xs text-red-500">−{{ $moneda((int) $sesion->getAttribute('efectivo_devuelto')) }} devuelto</span>
                                    @endif
                                    @if ($sesion->getAttribute('propina_efectivo') > 0)
                                        <span class="block text-xs text-amber-600">{{ $moneda((int) $sesion->getAttribute('propina_efectivo')) }} de propina</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ $moneda((int) $sesion->getAttribute('esperado_vivo')) }}</td>
                                <td class="px-5 py-3 text-right text-gray-900">
                                    {{ $sesion->isOpen() ? '—' : $moneda((int) $sesion->closing_cents) }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($sesion->isOpen())
                                        <span class="text-gray-400">—</span>
                                    @else
                                        @php($dif = (int) $sesion->difference_cents)
                                        <span class="font-medium {{ $dif === 0 ? 'text-teal-700' : ($dif < 0 ? 'text-red-600' : 'text-amber-600') }}">
                                            {{ $dif > 0 ? '+' : '' }}{{ $moneda($dif) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($sesiones->hasPages())
        <div class="mt-4">{{ $sesiones->links() }}</div>
    @endif

    <div class="mt-4 space-y-1 text-xs text-gray-500">
        <p>El esperado es el fondo más los cobros en efectivo, menos las devoluciones en efectivo del turno. Una diferencia negativa es un faltante en la gaveta.</p>
        <p>Las cajas se abren y se cierran desde el POS, junto al dinero: cerrar un turno desde aquí, sin contar los billetes, no sería un arqueo.</p>
        <p>La propina en efectivo está dentro del esperado — se cuenta con el resto de la gaveta — pero no es del negocio.</p>
    </div>
@endsection
