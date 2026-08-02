@extends($panelLayout)

@section('title', 'Mercancía — '.$event->name)

@section('content')
    @php
        $cant = fn ($n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';

        // El total del que responde el puesto, venga de donde venga: es el
        // mismo del que sale el porcentaje del faltante.
        $aCargo = fn ($l): float => $l->allocated + $l->purchased + $l->transferredIn;

        // De dónde salió ese total. Con una sola procedencia el número ya está
        // arriba y repetirlo solo estorba: basta con nombrarla.
        $origen = function ($l) use ($cant): string {
            $partes = [];

            foreach ([['entregado', $l->allocated], ['comprado', $l->purchased], ['recibido', $l->transferredIn]] as [$nombre, $n]) {
                if ($n > 0) {
                    $partes[] = [$nombre, $n];
                }
            }

            if (count($partes) <= 1) {
                return ($partes[0][0] ?? 'entregado') === 'entregado' ? '' : $partes[0][0];
            }

            return implode(' + ', array_map(fn (array $p): string => $cant($p[1]).' '.$p[0], $partes));
        };
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('event-panel.events.show', $event) }}" class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-800">
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $event->name }}
            </a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">Mercancía</h1>
            <p class="mt-1 text-sm text-gray-500">
                Lo que se entregó a cada puesto y lo que queda por explicar. La liquidación cuadra el dinero; esto, lo que bajó del camión.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('event-panel.events.settlement', $event) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Ver el dinero</a>
            @if ($puedeMover)
                <button type="button" data-hs-overlay="#modal-devolver" aria-haspopup="dialog"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Registrar devolución</button>
                <button type="button" data-hs-overlay="#modal-entregar" aria-haspopup="dialog"
                    class="rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sky-500">Entregar mercancía</button>
            @endif
        </div>
    </div>

    @if ($lineas->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-5 py-12 text-center">
            <p class="text-sm text-gray-500">Todavía no se ha movido mercancía en este evento.</p>
            <p class="mt-1 text-sm text-gray-400">Registra lo que le entregas a cada puesto y al cerrar sabrás qué falta.</p>
        </div>
    @else
        @if ($faltantes > 0)
            <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <strong class="font-medium">{{ $faltantes }}</strong>
                {{ $faltantes === 1 ? 'línea no cuadra' : 'líneas no cuadran' }}.
                Un faltante no es un fallo del sistema: es la pregunta que hay que hacerle a alguien antes de cerrar.
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Puesto e insumo</th>
                            <th class="px-5 py-3 text-right font-medium">A cargo</th>
                            <th class="px-5 py-3 text-right font-medium">Vendido</th>
                            <th class="px-5 py-3 text-right font-medium">Merma</th>
                            <th class="px-5 py-3 text-right font-medium">Devuelto</th>
                            <th class="px-5 py-3 text-right font-medium">Falta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($lineas as $linea)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-gray-800">{{ $linea->itemName }}</p>
                                    <p class="text-xs text-gray-500">{{ $linea->outletName }} · {{ $linea->vendorName }}</p>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-800">
                                    {{ $cant($aCargo($linea)) }}
                                    @if ($detalle = $origen($linea))
                                        <span class="block text-xs text-gray-500">{{ $detalle }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-gray-600">{{ $cant($linea->sold) }}</td>
                                <td class="px-5 py-3 text-right {{ $linea->wasted > 0 ? 'text-amber-600' : 'text-gray-400' }}">
                                    {{ $linea->wasted > 0 ? $cant($linea->wasted) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-gray-600">{{ $cant($linea->returned) }}</td>
                                <td class="px-5 py-3 text-right">
                                    @if (abs($linea->missing) < 0.0001)
                                        <span class="inline-flex items-center gap-1.5 text-xs text-teal-700">
                                            <span class="size-1.5 rounded-full bg-teal-500"></span>Cuadra
                                        </span>
                                    @else
                                        <span class="font-medium {{ $linea->missing > 0 ? 'text-red-600' : 'text-sky-700' }}">
                                            {{ $linea->missing > 0 ? '' : '+' }}{{ $cant(abs($linea->missing)) }}
                                        </span>
                                        <span class="block text-xs text-gray-500">
                                            {{ $linea->baseUnit }} · {{ number_format(abs($linea->missingPercent()), 1) }} %
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            Lo entregado, más lo comprado y recibido, menos lo vendido, mermado y devuelto.
            Lo vendido sale de las recetas, que descuentan solas al cobrar. Un ajuste de conteo ya explica su parte del hueco y no se cuenta dos veces.
            Una cifra en azul con signo más es mercancía que apareció de más.
        </p>
    @endif

    @if ($puedeMover)
        @foreach ([
            ['id' => 'entregar', 'titulo' => 'Entregar mercancía a un puesto', 'accion' => route('event-panel.events.stock.allocate', $event), 'boton' => 'Entregar', 'ayuda' => 'De dónde sale (opcional): si viene de la bodega del comercio, se le descuenta allí. Si viene de fuera del sistema, déjalo vacío.'],
            ['id' => 'devolver', 'titulo' => 'Registrar una devolución', 'accion' => route('event-panel.events.stock.return', $event), 'boton' => 'Devolver', 'ayuda' => 'A dónde vuelve (opcional): si regresa a la bodega del comercio, se le suma allí.'],
        ] as $modal)
            <div id="modal-{{ $modal['id'] }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
                <div class="m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-md">
                    <form method="POST" action="{{ $modal['accion'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                        @csrf
                        <h3 class="mb-4 font-medium text-gray-800">{{ $modal['titulo'] }}</h3>
                        <div class="space-y-3">
                            <div>
                                <label for="{{ $modal['id'] }}-puesto" class="mb-1.5 block text-sm text-gray-700">Puesto</label>
                                <select id="{{ $modal['id'] }}-puesto" name="outlet_id" required
                                    class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                                    @foreach ($puestos as $puesto)
                                        <option value="{{ $puesto->id }}" data-vendor="{{ $puesto->vendor_id }}">
                                            {{ $puesto->name }} — {{ $puesto->vendor?->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="{{ $modal['id'] }}-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo</label>
                                <select id="{{ $modal['id'] }}-insumo" name="inventory_item_id" required
                                    class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                                    @foreach ($insumosPorComercio as $vendorId => $insumos)
                                        @foreach ($insumos as $insumo)
                                            <option value="{{ $insumo->id }}" data-vendor="{{ $vendorId }}">
                                                {{ $insumo->name }} ({{ $insumo->base_unit->value }})
                                            </option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="{{ $modal['id'] }}-cantidad" class="mb-1.5 block text-sm text-gray-700">Cantidad</label>
                                <input id="{{ $modal['id'] }}-cantidad" name="quantity" type="number" step="0.001" min="0.001" required
                                    class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            </div>
                            <div>
                                <label for="{{ $modal['id'] }}-contra" class="mb-1.5 block text-sm text-gray-700">
                                    {{ $modal['id'] === 'entregar' ? 'Sale de' : 'Vuelve a' }} <span class="text-gray-400">(opcional)</span>
                                </label>
                                <select id="{{ $modal['id'] }}-contra" name="counterpart_id"
                                    class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                                    <option value="">Fuera del sistema</option>
                                    @foreach ($puestos as $puesto)
                                        <option value="{{ $puesto->id }}" data-vendor="{{ $puesto->vendor_id }}">
                                            {{ $puesto->name }} — {{ $puesto->vendor?->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-xs text-gray-500">{{ $modal['ayuda'] }}</p>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-{{ $modal['id'] }}">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">{{ $modal['boton'] }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach

        {{-- Los insumos son de cada comercio: mostrar los de otro solo lleva
             a un 404 al enviar. El selector se filtra por el puesto elegido. --}}
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                for (const id of ['entregar', 'devolver']) {
                    const puesto = document.querySelector(`#${id}-puesto`);
                    const insumo = document.querySelector(`#${id}-insumo`);
                    const contra = document.querySelector(`#${id}-contra`);
                    if (!puesto || !insumo) continue;

                    const filtrar = () => {
                        const vendor = puesto.selectedOptions[0]?.dataset.vendor;
                        let primera = null;

                        for (const opcion of insumo.options) {
                            const suyo = opcion.dataset.vendor === vendor;
                            opcion.hidden = !suyo;
                            opcion.disabled = !suyo;
                            if (suyo && primera === null) primera = opcion;
                        }
                        if (primera) primera.selected = true;

                        for (const opcion of contra?.options ?? []) {
                            if (opcion.value === '') continue;
                            const suyo = opcion.dataset.vendor === vendor && opcion.value !== puesto.value;
                            opcion.hidden = !suyo;
                            opcion.disabled = !suyo;
                        }
                        if (contra) contra.value = '';
                    };

                    puesto.addEventListener('change', filtrar);
                    filtrar();
                }
            });
        </script>
    @endif
@endsection
