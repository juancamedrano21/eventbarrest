{{-- Parcial compartido entre /panel (organizador) y /comercio (encargado):
     las acciones llegan en $urls, cada puerta pone las suyas. $puede acota
     por capacidades; sin él, todo permitido (el organizador ya autorizó). --}}
    {{-- OJO: bloque @php/@endphp, nunca @php(...) inline — este archivo tiene
         más bloques @php y el inline se emparejaría con el primer @endphp. --}}
    @php
        $puedeCatalogo = $puede['catalogo'] ?? true;
    @endphp
    {{-- Tab: Menú --}}
    <div id="tab-menu" class="hidden" role="tabpanel" aria-labelledby="tab-menu-item">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">El menú del comercio, clasificado en <span class="font-medium text-gray-700">Alimentos</span> (salen de cocina) y <span class="font-medium text-gray-700">Bebidas</span> (salen de barra).</p>
            @if ($puedeCatalogo)
                <div class="flex gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                        aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-categoria" data-hs-overlay="#modal-categoria">
                        Nueva categoría
                    </button>
                    <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                        aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-producto" data-hs-overlay="#modal-producto">
                        Nuevo producto
                    </button>
                </div>
            @endif
        </div>

        @forelse ($menuCategories as $categoria)
            <section class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-5 py-3">
                    <h3 class="font-medium text-gray-800">{{ $categoria->name }}</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-xs {{ $categoria->dispatch->value === 'kitchen' ? 'bg-orange-100 text-orange-800' : 'bg-sky-100 text-sky-800' }}">
                        {{ $categoria->dispatch->value === 'kitchen' ? 'Alimentos' : 'Bebidas' }}
                    </span>
                    <span class="text-xs text-gray-400">{{ $categoria->products->count() }} producto(s)</span>
                </header>
                <ul class="divide-y divide-gray-200">
                    @forelse ($categoria->products as $product)
                        <li>
                            <button type="button" class="flex w-full items-center gap-4 px-5 py-3.5 text-left text-sm transition {{ $puedeCatalogo ? 'hover:bg-gray-50' : 'cursor-default' }}"
                                @if ($puedeCatalogo) aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-item-{{ $product->id }}" data-hs-overlay="#modal-item-{{ $product->id }}" @endif>
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br {{ $product->active ? 'from-sky-100 to-sky-200 text-sky-700' : 'from-gray-100 to-gray-200 text-gray-400' }} text-base font-semibold">
                                    {{ Str::upper(Str::substr($product->name, 0, 1)) }}
                                </span>
                                <span class="min-w-0 grow">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate text-gray-800 {{ $product->active ? '' : 'line-through opacity-60' }}">{{ $product->name }}</span>
                                        @unless ($product->active)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">Pausado</span>
                                        @endunless
                                        @if ($product->itbis_exempt)
                                            <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] text-violet-700">Exento de ITBIS</span>
                                        @endif
                                    </span>
                                    <span class="mt-0.5 block text-xs text-gray-500">
                                        @if ($product->type->value === 'recipe')
                                            Receta · {{ $product->recipeItems->count() }} ingrediente(s)
                                        @elseif ($product->inventoryItem)
                                            Descuenta: {{ $product->inventoryItem->name }}
                                        @else
                                            Sin control de inventario
                                        @endif
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-medium text-gray-800">RD$ {{ number_format($product->price_cents / 100, 2) }}</span>
                                    @if ($puedeCatalogo)
                                        <span class="block text-[11px] text-gray-400">Editar</span>
                                    @endif
                                </span>
                                @if ($puedeCatalogo)
                                    <svg class="size-4 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-gray-500">Sin productos en esta categoría.</li>
                    @endforelse
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-12 text-center text-sm text-gray-500">
                El menú está vacío: crea la primera categoría (Alimentos o Bebidas) y añade sus productos.
            </div>
        @endforelse

        {{-- Modal premium por ítem: precio y todas las configuraciones --}}
        @if ($puedeCatalogo)
        @foreach ($menuCategories as $categoria)
            @foreach ($categoria->products as $product)
                <div id="modal-item-{{ $product->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1" aria-labelledby="modal-item-{{ $product->id }}-label">
                    <div class="m-3 mt-8 opacity-0 transition-all ease-out hs-overlay-open:mt-14 hs-overlay-open:opacity-100 hs-overlay-open:duration-300 sm:mx-auto sm:w-full sm:max-w-lg">
                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl">

                            {{-- Cabecera --}}
                            <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-700 text-lg font-semibold text-white">
                                    {{ Str::upper(Str::substr($product->name, 0, 1)) }}
                                </span>
                                <div class="min-w-0 grow">
                                    <h3 id="modal-item-{{ $product->id }}-label" class="truncate font-medium text-gray-800">{{ $product->name }}</h3>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                                        <span class="rounded-full px-2 py-0.5 {{ $categoria->dispatch->value === 'kitchen' ? 'bg-orange-100 text-orange-800' : 'bg-sky-100 text-sky-800' }}">{{ $categoria->dispatch->value === 'kitchen' ? 'Alimentos' : 'Bebidas' }}</span>
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{{ $product->type->value === 'recipe' ? 'Con receta' : 'Simple' }}</span>
                                    </p>
                                </div>
                                <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100" aria-label="Cerrar" data-hs-overlay="#modal-item-{{ $product->id }}">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                </button>
                            </div>

                            {{-- Configuración --}}
                            @php
                                // Si ESTE modal falló la validación, reabre con lo
                                // tecleado; los demás muestran su estado real.
                                $conError = $errors->any() && old('_modal') === 'modal-item-'.$product->id;
                            @endphp
                            <form method="POST" action="{{ $urls['producto']($product) }}">
                                @csrf
                                <input type="hidden" name="_modal" value="modal-item-{{ $product->id }}">
                                <div class="space-y-4 px-5 py-5">
                                    <div>
                                        <label class="mb-1.5 block text-xs font-medium text-gray-700">Nombre</label>
                                        <input name="name" value="{{ $conError ? old('name') : $product->name }}" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 {{ $conError && $errors->has('name') ? 'border-red-300' : '' }}">
                                        @if ($conError)
                                            @error('name')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-gray-700">Precio (RD$)</label>
                                            <input name="price" type="text" inputmode="decimal" value="{{ $conError ? old('price') : number_format($product->price_cents / 100, 2, '.', '') }}" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                            @if ($conError)
                                                @error('price')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                                            @endif
                                        </div>
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-gray-700">Categoría</label>
                                            <select name="category_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                                @foreach ($menuCategories as $opcion)
                                                    <option value="{{ $opcion->id }}" @selected($conError ? (string) old('category_id') === (string) $opcion->id : $opcion->id === $product->category_id)>{{ $opcion->name }} — {{ $opcion->dispatch->value === 'kitchen' ? 'Alimentos' : 'Bebidas' }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-gray-700">Estado</label>
                                            <select name="active" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                                <option value="1" @selected($conError ? old('active') === '1' : $product->active)>En venta</option>
                                                <option value="0" @selected($conError ? old('active') === '0' : ! $product->active)>Pausado — no aparece en el POS</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-gray-700">ITBIS</label>
                                            <select name="itbis_exempt" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                                <option value="0" @selected($conError ? old('itbis_exempt') === '0' : ! $product->itbis_exempt)>Grava — 18 % incluido en el precio</option>
                                                <option value="1" @selected($conError ? old('itbis_exempt') === '1' : $product->itbis_exempt)>Exento — no desglosa impuesto</option>
                                            </select>
                                        </div>
                                    </div>

                                    @if ($product->type->value !== 'recipe')
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-gray-700">Insumo que descuenta</label>
                                            <select name="inventory_item_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                                <option value="">Sin insumo — no descuenta inventario</option>
                                                @foreach ($vendorItems as $id => $name)
                                                    <option value="{{ $id }}" @selected($conError ? (string) old('inventory_item_id') === (string) $id : $id === $product->inventory_item_id)>{{ $name }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1.5 text-xs text-gray-500">Vende 1, descuenta 1 (ej. una cerveza descuenta su botella).</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-item-{{ $product->id }}">Cancelar</button>
                                    <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500">Guardar cambios</button>
                                </div>
                            </form>

                            {{-- Escandallo (solo recetas): sus propios formularios --}}
                            @if ($product->type->value === 'recipe')
                                <div class="border-t border-gray-200 px-5 py-5">
                                    <p class="font-medium text-gray-800">Receta (escandallo)</p>
                                    <p class="mb-3 mt-0.5 text-xs text-gray-500">Lo que cada venta descuenta del inventario, en la unidad base de cada insumo.</p>

                                    <ul class="mb-3 divide-y divide-gray-200 rounded-lg border border-gray-200">
                                        @forelse ($product->recipeItems as $ingrediente)
                                            <li class="flex items-center justify-between px-3 py-2 text-sm">
                                                <span class="text-gray-800">{{ $ingrediente->inventoryItem?->name }}</span>
                                                <span class="flex items-center gap-2">
                                                    <span class="text-gray-500">{{ number_format((float) $ingrediente->quantity, 3) }} {{ $ingrediente->inventoryItem?->base_unit->short() }}</span>
                                                    <form method="POST" action="{{ $urls['recetaQuitar']($product, $ingrediente) }}">
                                                        @csrf
                                                        <button type="submit" class="text-xs text-red-600 hover:text-red-700">Quitar</button>
                                                    </form>
                                                </span>
                                            </li>
                                        @empty
                                            <li class="px-3 py-3 text-sm text-gray-500">Sin ingredientes: este producto aún no descuenta nada.</li>
                                        @endforelse
                                    </ul>

                                    <form method="POST" action="{{ $urls['receta']($product) }}" class="flex gap-2">
                                        @csrf
                                        <select name="inventory_item_id" required class="grow rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                                            @forelse ($vendorItems as $id => $name)
                                                <option value="{{ $id }}">{{ $name }}</option>
                                            @empty
                                                <option value="" disabled>Primero crea un insumo (pestaña Inventario)</option>
                                            @endforelse
                                        </select>
                                        <input name="quantity" type="text" inputmode="decimal" placeholder="Cant." required class="w-24 rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                                        <button type="submit" class="rounded-lg bg-sky-600 px-3 py-2 text-sm font-medium text-white hover:bg-sky-500">Añadir</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach

        @php
            // Solo un id con la forma esperada reabre: nada del old() viaja
            // crudo a un selector.
            $modalConError = $errors->any() && preg_match('/^modal-item-\d+$/', (string) old('_modal')) === 1
                ? old('_modal')
                : null;
        @endphp
        @if ($modalConError !== null)
            <script>
                // La validación devolvió al perfil: reabre el modal del ítem
                // que falló, con lo tecleado y el error a la vista.
                window.addEventListener('load', function () {
                    setTimeout(function () {
                        var modal = document.querySelector(@js('#'.$modalConError));
                        if (window.HSOverlay && modal) { window.HSOverlay.open(modal); }
                    }, 150);
                });
            </script>
        @endif
        @endif
    </div>

