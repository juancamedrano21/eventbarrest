{{--
    Los formularios que mueven stock. Todos piden sucursal e insumo porque el
    stock vive por sucursal; el insumo, en cambio, es del negocio entero.
--}}

{{-- Nuevo insumo --}}
<div id="modal-insumo" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
    <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
        <form method="POST" action="{{ route('business.items.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
            @csrf
            <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Nuevo insumo</h3></div>
            <div class="space-y-4 p-5">
                <div>
                    <label for="i-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                    <input id="i-nombre" name="name" required maxlength="255"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                </div>
                <div>
                    <label for="i-unidad" class="mb-1.5 block text-sm text-gray-700">Unidad base</label>
                    <select id="i-unidad" name="base_unit" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        <option value="ml">Mililitros</option>
                        <option value="g">Gramos</option>
                        <option value="unidad">Unidades</option>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">Recetas, compras y mermas hablarán siempre en esta unidad. Una botella de 750 ml son 750.</p>
                </div>
                <div>
                    <label for="i-costo" class="mb-1.5 block text-sm text-gray-700">Costo por unidad (RD$)</label>
                    <input id="i-costo" name="cost" type="number" step="0.0001" min="0"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" data-hs-overlay="#modal-insumo" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Crear</button>
            </div>
        </form>
    </div>
</div>

{{-- Editar insumo --}}
@foreach ($insumos as $insumo)
    <div id="modal-insumo-{{ $insumo->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('business.items.update', $insumo) }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="font-medium text-gray-900">{{ $insumo->name }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Se mide en {{ $insumo->base_unit->getLabel() }}</p>
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="ie-nombre-{{ $insumo->id }}" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="ie-nombre-{{ $insumo->id }}" name="name" value="{{ $insumo->name }}" required maxlength="255"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="ie-costo-{{ $insumo->id }}" class="mb-1.5 block text-sm text-gray-700">Costo por unidad (RD$)</label>
                        <input id="ie-costo-{{ $insumo->id }}" name="cost" type="number" step="0.0001" min="0"
                            value="{{ number_format($insumo->cost_cents / 100, 4, '.', '') }}"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        <p class="mt-1.5 text-xs text-gray-500">Cada compra lo recalcula como promedio ponderado.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-insumo-{{ $insumo->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                </div>
            </form>
        </div>
    </div>
@endforeach

{{-- Compra --}}
<div id="modal-compra" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
    <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-lg">
        <form method="POST" action="{{ route('business.purchases.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
            @csrf
            <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Registrar compra</h3></div>
            <div class="space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="c-sucursal" class="mb-1.5 block text-sm text-gray-700">Entra en</label>
                        <select id="c-sucursal" name="operating_unit_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            @foreach ($sucursales as $sucursal)
                                <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="c-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo</label>
                        <select id="c-insumo" name="inventory_item_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            @foreach ($insumos as $insumo)
                                <option value="{{ $insumo->id }}">{{ $insumo->name }} ({{ $insumo->base_unit->value }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="c-cantidad" class="mb-1.5 block text-sm text-gray-700">Cantidad</label>
                        <input id="c-cantidad" name="quantity" type="number" step="0.001" min="0.001" required
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="c-costo" class="mb-1.5 block text-sm text-gray-700">Costo por unidad (RD$)</label>
                        <input id="c-costo" name="unit_cost" type="number" step="0.0001" min="0" required
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label for="c-referencia" class="mb-1.5 block text-sm text-gray-700">Referencia <span class="text-gray-400">(opcional)</span></label>
                    <input id="c-referencia" name="reference" maxlength="255" placeholder="Nº de factura, proveedor…"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" data-hs-overlay="#modal-compra" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Registrar</button>
            </div>
        </form>
    </div>
</div>

@if ($puede['ajustar'])
    {{-- Ajuste de conteo --}}
    <div id="modal-ajuste" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.adjustments.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Ajuste de conteo</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="a-sucursal" class="mb-1.5 block text-sm text-gray-700">Sucursal</label>
                            <select id="a-sucursal" name="operating_unit_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="a-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo</label>
                            <select id="a-insumo" name="inventory_item_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($insumos as $insumo)
                                    <option value="{{ $insumo->id }}">{{ $insumo->name }} ({{ $insumo->base_unit->value }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="a-cantidad" class="mb-1.5 block text-sm text-gray-700">Diferencia</label>
                        <input id="a-cantidad" name="quantity" type="number" step="0.001" required
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        <p class="mt-1.5 text-xs text-gray-500">Lo contado menos lo que dice el sistema. Positivo si aparece de más, negativo si falta.</p>
                    </div>
                    <div>
                        <label for="a-motivo" class="mb-1.5 block text-sm text-gray-700">Motivo</label>
                        <input id="a-motivo" name="reason" required maxlength="255" placeholder="Conteo físico del lunes"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-ajuste" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Registrar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Merma --}}
    <div id="modal-merma" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.waste.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Merma</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="m-sucursal" class="mb-1.5 block text-sm text-gray-700">Sucursal</label>
                            <select id="m-sucursal" name="operating_unit_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="m-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo</label>
                            <select id="m-insumo" name="inventory_item_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($insumos as $insumo)
                                    <option value="{{ $insumo->id }}">{{ $insumo->name }} ({{ $insumo->base_unit->value }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="m-cantidad" class="mb-1.5 block text-sm text-gray-700">Cantidad perdida</label>
                        <input id="m-cantidad" name="quantity" type="number" step="0.001" min="0.001" required
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="m-motivo" class="mb-1.5 block text-sm text-gray-700">Motivo</label>
                        <input id="m-motivo" name="reason" required maxlength="255" placeholder="Botella rota, producto vencido…"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-merma" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Registrar</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if ($puede['trasladar'] && $sucursales->count() > 1)
    {{-- Traslado --}}
    <div id="modal-traslado" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.transfers.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Traslado entre sucursales</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="t-origen" class="mb-1.5 block text-sm text-gray-700">Sale de</label>
                            <select id="t-origen" name="from_unit_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="t-destino" class="mb-1.5 block text-sm text-gray-700">Entra en</label>
                            <select id="t-destino" name="to_unit_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}" @selected($loop->index === 1)>{{ $sucursal->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="t-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo</label>
                            <select id="t-insumo" name="inventory_item_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($insumos as $insumo)
                                    <option value="{{ $insumo->id }}">{{ $insumo->name }} ({{ $insumo->base_unit->value }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="t-cantidad" class="mb-1.5 block text-sm text-gray-700">Cantidad</label>
                            <input id="t-cantidad" name="quantity" type="number" step="0.001" min="0.001" required
                                class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-traslado" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Trasladar</button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- Umbral, uno por existencia --}}
@foreach ($existencias as $nivel)
    <div id="modal-umbral-{{ $nivel->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-24 sm:mx-auto sm:w-full sm:max-w-sm">
            <form method="POST" action="{{ route('business.thresholds.update', $nivel) }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="font-medium text-gray-900">{{ $nivel->inventoryItem?->name }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $nivel->operatingUnit?->name }}</p>
                </div>
                <div class="p-5">
                    <label for="u-{{ $nivel->id }}" class="mb-1.5 block text-sm text-gray-700">Avisar por debajo de</label>
                    <input id="u-{{ $nivel->id }}" name="alert_threshold" type="number" step="0.001" min="0" required
                        value="{{ rtrim(rtrim(number_format((float) $nivel->alert_threshold, 3, '.', ''), '0'), '.') ?: '0' }}"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    <p class="mt-1.5 text-xs text-gray-500">En {{ $nivel->inventoryItem?->base_unit?->getLabel() }}. Cero desactiva el aviso.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-umbral-{{ $nivel->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
