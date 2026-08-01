{{-- Parcial compartido entre /panel (organizador) y /comercio (encargado):
     las acciones llegan en $urls, cada puerta pone las suyas. --}}
    {{-- Tab: Inventario --}}
    <div id="tab-inventario" class="hidden" role="tabpanel" aria-labelledby="tab-inventario-item">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr><th class="px-5 py-3">Insumo</th><th class="px-5 py-3">Puesto</th><th class="px-5 py-3 text-right">Existencia</th><th class="px-5 py-3">Estado</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($stockLevels as $level)
                        <tr>
                            <td class="px-5 py-3 text-gray-800">{{ $level->inventoryItem?->name }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $level->operatingUnit?->name }}</td>
                            <td class="px-5 py-3 text-right text-gray-800">{{ number_format((float) $level->quantity, 3) }} {{ $level->inventoryItem?->base_unit->short() }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs {{ $level->isLow() ? 'bg-red-100 text-red-800' : 'bg-teal-100 text-teal-800' }}">{{ $level->isLow() ? 'Bajo mínimo' : 'OK' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">Sin existencias: su encargado registra las compras desde su panel.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($puede['inventario'] ?? true)
            <div class="mt-4 flex gap-2">
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-compra" data-hs-overlay="#modal-compra">
                    Registrar compra
                </button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-insumo" data-hs-overlay="#modal-insumo">
                    Nuevo insumo
                </button>
            </div>
        @endif
    </div>

