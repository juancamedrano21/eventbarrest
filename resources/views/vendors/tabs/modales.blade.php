{{-- Parcial compartido entre /panel (organizador) y /comercio (encargado):
     las acciones llegan en $urls, cada puerta pone las suyas. $puede acota
     por capacidades; sin él, todo permitido (el organizador ya autorizó). --}}
{{-- Bloque, nunca @php(...) inline: el inline se empareja con el primer
     @endphp del archivo y se traga las directivas siguientes. --}}
@php
    $itbisVaAparte = ($modoVigente ?? null)?->value === 'added';
@endphp
@if ($puede['catalogo'] ?? true)
{{-- Modal: nueva categoría --}}
    <div id="modal-categoria" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ $urls['categorias'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nueva categoría del menú</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Cervezas, Tacos, Postres..." required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <select name="tipo" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="bebidas">Bebidas — salen de barra</option>
                        <option value="alimentos">Alimentos — salen de cocina</option>
                    </select>
                    <p class="text-xs text-gray-500">Esta clasificación decide qué POS la muestra y por qué impresora saldrán las comandas.</p>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-categoria">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear categoría</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: nuevo producto --}}
    <div id="modal-producto" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ $urls['productos'] }}" enctype="multipart/form-data" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo producto del menú</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del producto" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <input name="price" type="text" inputmode="decimal" value="{{ old('price') }}" placeholder="{{ $itbisVaAparte ? 'Precio sin ITBIS (RD$)' : 'Precio con ITBIS incluido (RD$)' }}" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <select name="category_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        @forelse ($menuCategories as $categoria)
                            <option value="{{ $categoria->id }}">{{ $categoria->name }} — {{ $categoria->dispatch->value === 'kitchen' ? 'Alimentos' : 'Bebidas' }}</option>
                        @empty
                            <option value="" disabled>Primero crea una categoría</option>
                        @endforelse
                    </select>
                    <select name="kind" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="simple">Simple — puede descontar UN insumo por venta</option>
                        <option value="receta">Con receta — descuenta varios ingredientes (el escandallo se arma después)</option>
                    </select>
                    <select name="itbis" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="gravado">{{ $itbisVaAparte ? 'Grava — el 18 % se suma al cobrar' : 'Grava — el 18 % va incluido en el precio' }}</option>
                        <option value="exento">Exento — agua embotellada, alimentos no gravados</option>
                    </select>
                    <select name="inventory_item_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="">Sin insumo vinculado (solo para Simple)</option>
                        @foreach ($vendorItems as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Simple + insumo: vende 1, descuenta 1 (ej. cerveza). Con receta: crea el producto y ábrele «Receta» en su fila para armar el escandallo.</p>
                    <div>
                        <label for="np-foto" class="mb-1.5 block text-sm text-gray-700">Foto <span class="text-gray-400">(opcional)</span></label>
                        <input id="np-foto" name="image" type="file" accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:text-gray-700 hover:file:bg-gray-200">
                        <p class="mt-1.5 text-xs text-gray-500">JPG, PNG o WebP, hasta 4 MB. Es lo que ve el cajero en el POS.</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-producto">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear producto</button>
                </div>
            </form>
        </div>
    </div>

@endif

@if ($puede['inventario'] ?? true)
    {{-- Modal: nuevo insumo --}}
    <div id="modal-insumo" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ $urls['insumos'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo insumo</h3>
                <div class="space-y-3">
                    <input name="name" placeholder="Ron blanco, limones..." required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <select name="base_unit" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="ml">Mililitros</option>
                        <option value="g">Gramos</option>
                        <option value="unidad">Unidades</option>
                    </select>
                    <input name="cost" type="text" inputmode="decimal" placeholder="Costo por unidad base (RD$, opcional)" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-insumo">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear insumo</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: registrar compra --}}
    <div id="modal-compra" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ $urls['compras'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Registrar compra</h3>
                <div class="space-y-3">
                    <select name="operating_unit_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        @forelse ($outlets as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }} — {{ $outlet->event?->name }}</option>
                        @empty
                            <option value="" disabled>Sin puestos: el organizador del evento debe crearlos</option>
                        @endforelse
                    </select>
                    <select name="inventory_item_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        @forelse ($vendorItems as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @empty
                            <option value="" disabled>Primero crea un insumo</option>
                        @endforelse
                    </select>
                    <input name="quantity" type="text" inputmode="decimal" placeholder="Cantidad recibida" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <input name="unit_cost" type="text" inputmode="decimal" placeholder="Costo unitario pagado (RD$)" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <input name="reference" placeholder="Factura o proveedor (opcional)" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-compra">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Registrar</button>
                </div>
            </form>
        </div>
    </div>

@endif
