@extends('business.layout')

@section('title', 'Menú')

@section('content')
    @php
        // Siempre en bloque: la forma corta de esta misma directiva se
        // empareja con el primer cierre del archivo y se traga lo de en medio.
        $productos = $categorias->flatMap->products;
        $conError = fn (string $modal): bool => $errors->any() && old('_modal') === $modal;
        $moneda = fn (int $cents): string => 'RD$ '.number_format($cents / 100, 2);
        $cantidad = fn ($n): string => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Menú</h1>
            <p class="mt-1 text-sm text-gray-500">
                {{ $productos->count() }} {{ $productos->count() === 1 ? 'producto' : 'productos' }}
                en {{ $categorias->count() }} {{ $categorias->count() === 1 ? 'categoría' : 'categorías' }}.
                Un solo menú para todas las sucursales.
            </p>
        </div>
        <div class="flex gap-2">
            <button type="button" data-hs-overlay="#modal-categoria" aria-haspopup="dialog"
                class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm text-gray-700 hover:bg-gray-50">Nueva categoría</button>
            <button type="button" data-hs-overlay="#modal-producto" aria-haspopup="dialog" @disabled($categorias->isEmpty())
                class="rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-40">Nuevo producto</button>
        </div>
    </div>

    <div class="mb-6 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm text-gray-600">
        Los precios se escriben con el ITBIS <strong class="font-medium text-gray-800">{{ mb_strtolower($modoVigente->getLabel()) }}</strong>. {{ $modoVigente->description() }}
    </div>

    @if ($categorias->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-5 py-10 text-center">
            <p class="text-sm text-gray-500">El menú está vacío.</p>
            <p class="mt-1 text-sm text-gray-400">Empieza creando una categoría — «Cervezas», «Platos», «Cócteles».</p>
        </div>
    @endif

    @foreach ($categorias as $categoria)
        <div class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                <div class="flex items-center gap-2.5">
                    <h2 class="font-medium text-gray-900">{{ $categoria->name }}</h2>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                        {{ $categoria->dispatch->value === 'kitchen' ? 'Cocina' : 'Barra' }}
                    </span>
                </div>
                <button type="button" data-hs-overlay="#modal-categoria-{{ $categoria->id }}" aria-haspopup="dialog"
                    class="text-xs text-gray-500 hover:text-gray-800">Editar</button>
            </div>

            @if ($categoria->products->isEmpty())
                <p class="px-5 py-6 text-sm text-gray-500">Sin productos en esta categoría.</p>
            @else
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($categoria->products as $producto)
                            <tr class="{{ $producto->active ? '' : 'bg-gray-50/60' }}">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-gray-900">{{ $producto->name }}</span>
                                    @unless ($producto->active)
                                        <span class="ml-2 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600">Inactivo</span>
                                    @endunless
                                    @if ($producto->itbis_exempt)
                                        <span class="ml-2 rounded bg-sky-50 px-1.5 py-0.5 text-xs text-sky-700">Exento</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-500">
                                    @if ($producto->type->value === 'recipe')
                                        Receta · {{ $producto->recipeItems->count() }} {{ $producto->recipeItems->count() === 1 ? 'insumo' : 'insumos' }}
                                    @elseif ($producto->inventoryItem)
                                        Descuenta {{ $producto->inventoryItem->name }}
                                    @else
                                        Sin inventario
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ $moneda($producto->price_cents) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button type="button" data-hs-overlay="#modal-item-{{ $producto->id }}" aria-haspopup="dialog"
                                        class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    {{-- ───────── Modales ───────── --}}

    <div id="modal-categoria" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('business.categories.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Nueva categoría</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="cat-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="cat-nombre" name="name" required maxlength="255"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="cat-tipo" class="mb-1.5 block text-sm text-gray-700">Qué es</label>
                        <select id="cat-tipo" name="tipo" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            <option value="bebidas">Bebidas — salen de la barra</option>
                            <option value="alimentos">Alimentos — salen de la cocina</option>
                        </select>
                        <p class="mt-1.5 text-xs text-gray-500">Decide por qué impresora sale la comanda.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-categoria" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Crear</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ($categorias as $categoria)
        <div id="modal-categoria-{{ $categoria->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-md">
                <form method="POST" action="{{ route('business.categories.update', $categoria) }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                    @csrf
                    <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">{{ $categoria->name }}</h3></div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label for="cate-nombre-{{ $categoria->id }}" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                            <input id="cate-nombre-{{ $categoria->id }}" name="name" value="{{ $categoria->name }}" required maxlength="255"
                                class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label for="cate-tipo-{{ $categoria->id }}" class="mb-1.5 block text-sm text-gray-700">Qué es</label>
                            <select id="cate-tipo-{{ $categoria->id }}" name="tipo" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="bebidas" @selected($categoria->dispatch->value === 'bar')>Bebidas — salen de la barra</option>
                                <option value="alimentos" @selected($categoria->dispatch->value === 'kitchen')>Alimentos — salen de la cocina</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                        <button type="button" data-hs-overlay="#modal-categoria-{{ $categoria->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                        <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <div id="modal-producto" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.products.store') }}" enctype="multipart/form-data" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3"><h3 class="font-medium text-gray-900">Nuevo producto</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="p-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="p-nombre" name="name" required maxlength="255"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="p-precio" class="mb-1.5 block text-sm text-gray-700">Precio (RD$)</label>
                            <input id="p-precio" name="price" type="number" step="0.01" min="0" required
                                class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label for="p-categoria" class="mb-1.5 block text-sm text-gray-700">Categoría</label>
                            <select id="p-categoria" name="category_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                @foreach ($categorias as $categoria)
                                    <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="p-kind" class="mb-1.5 block text-sm text-gray-700">Tipo</label>
                            <select id="p-kind" name="kind" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="simple">Simple — descuenta un insumo</option>
                                <option value="receta">Receta — descuenta varios</option>
                            </select>
                        </div>
                        <div>
                            <label for="p-itbis" class="mb-1.5 block text-sm text-gray-700">ITBIS</label>
                            <select id="p-itbis" name="itbis" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="gravado">Gravado (18 %)</option>
                                <option value="exento">Exento</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="p-insumo" class="mb-1.5 block text-sm text-gray-700">Insumo que descuenta <span class="text-gray-400">(opcional)</span></label>
                        <select id="p-insumo" name="inventory_item_id" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            <option value="">Sin control de stock</option>
                            @foreach ($insumos as $insumo)
                                <option value="{{ $insumo->id }}">{{ $insumo->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-gray-500">Para recetas se ignora: el escandallo se arma después, insumo a insumo.</p>
                    </div>
                    <div>
                        <label for="p-foto" class="mb-1.5 block text-sm text-gray-700">Foto <span class="text-gray-400">(opcional)</span></label>
                        <input id="p-foto" name="image" type="file" accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:text-gray-700 hover:file:bg-gray-200">
                        <p class="mt-1.5 text-xs text-gray-500">JPG, PNG o WebP, hasta 4 MB. Es lo que ve el cajero en el POS.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-producto" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Crear</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Un modal por producto: precio, fiscalidad, estado y escandallo --}}
    @foreach ($productos as $producto)
        <div id="modal-item-{{ $producto->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-12 sm:mx-auto sm:w-full sm:max-w-xl">
                <div class="rounded-xl border border-gray-200 bg-white shadow-lg">
                    @php
                        // Ambos son nulos cuando el costo no se puede saber:
                        // una receta sin insumos, un producto sin inventario.
                        // Se dice, no se finge un cero.
                        $costo = $producto->costCents();
                        $margen = $producto->marginPercent();
                    @endphp
                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-3">
                        <div>
                            <h3 class="font-medium text-gray-900">{{ $producto->name }}</h3>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ $producto->type->value === 'recipe' ? 'Receta' : 'Producto simple' }}
                                · costo {{ $costo === null ? 'sin definir' : $moneda($costo) }}
                            </p>
                        </div>
                        @if ($margen !== null)
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $margen < 0 ? 'bg-red-50 text-red-700' : ($margen < 30 ? 'bg-amber-50 text-amber-700' : 'bg-teal-50 text-teal-700') }}">
                                Margen {{ number_format($margen, 1) }} %
                            </span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('business.products.update', $producto) }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="_modal" value="modal-item-{{ $producto->id }}">
                        <div class="space-y-4 p-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="ep-nombre-{{ $producto->id }}" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                                    <input id="ep-nombre-{{ $producto->id }}" name="name"
                                        value="{{ $conError('modal-item-'.$producto->id) ? old('name') : $producto->name }}" maxlength="255"
                                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                </div>
                                <div>
                                    <label for="ep-precio-{{ $producto->id }}" class="mb-1.5 block text-sm text-gray-700">Precio (RD$)</label>
                                    <input id="ep-precio-{{ $producto->id }}" name="price" type="number" step="0.01" min="0"
                                        value="{{ $conError('modal-item-'.$producto->id) ? old('price') : number_format($producto->price_cents / 100, 2, '.', '') }}"
                                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                </div>
                            </div>
                            <div>
                                <label for="ep-categoria-{{ $producto->id }}" class="mb-1.5 block text-sm text-gray-700">Categoría</label>
                                <select id="ep-categoria-{{ $producto->id }}" name="category_id" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                    @foreach ($categorias as $categoria)
                                        <option value="{{ $categoria->id }}" @selected($producto->category_id === $categoria->id)>{{ $categoria->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if ($producto->type->value !== 'recipe')
                                <div>
                                    <label for="ep-insumo-{{ $producto->id }}" class="mb-1.5 block text-sm text-gray-700">Insumo que descuenta</label>
                                    <select id="ep-insumo-{{ $producto->id }}" name="inventory_item_id" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                        <option value="">Sin control de stock</option>
                                        @foreach ($insumos as $insumo)
                                            <option value="{{ $insumo->id }}" @selected($producto->inventory_item_id === $insumo->id)>{{ $insumo->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div>
                                <label for="ep-foto-{{ $producto->id }}" class="mb-1.5 block text-sm text-gray-700">Foto</label>
                                <div class="flex items-center gap-3">
                                    @if ($producto->imageUrl())
                                        <img src="{{ $producto->imageUrl() }}" alt="" class="size-14 shrink-0 rounded object-cover">
                                    @endif
                                    <input id="ep-foto-{{ $producto->id }}" name="image" type="file" accept="image/jpeg,image/png,image/webp"
                                        class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:text-gray-700 hover:file:bg-gray-200">
                                </div>
                                @if ($producto->imageUrl())
                                    <label class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                                        <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300">
                                        Quitar la foto actual
                                    </label>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-5">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="active" value="0">
                                    <input type="checkbox" name="active" value="1" @checked($producto->active) class="rounded border-gray-300">
                                    Activo en el POS
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="itbis_exempt" value="0">
                                    <input type="checkbox" name="itbis_exempt" value="1" @checked($producto->itbis_exempt) class="rounded border-gray-300">
                                    Exento de ITBIS
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                            <button type="button" data-hs-overlay="#modal-item-{{ $producto->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cerrar</button>
                            <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                        </div>
                    </form>

                    @if ($producto->type->value === 'recipe')
                        <div class="border-t border-gray-200 px-5 py-4">
                            <p class="mb-2 text-sm font-medium text-gray-800">Escandallo</p>
                            @if ($producto->recipeItems->isEmpty())
                                <p class="mb-3 text-sm text-gray-500">Sin insumos: esta receta no descuenta nada al venderse.</p>
                            @else
                                <ul class="mb-3 divide-y divide-gray-100 text-sm">
                                    @foreach ($producto->recipeItems as $ingrediente)
                                        <li class="flex items-center justify-between py-2">
                                            <span class="text-gray-800">{{ $ingrediente->inventoryItem?->name }}</span>
                                            <span class="flex items-center gap-3">
                                                <span class="text-gray-500">{{ $cantidad($ingrediente->quantity) }} {{ $ingrediente->inventoryItem?->base_unit?->value }}</span>
                                                <form method="POST" action="{{ route('business.recipe.destroy', [$producto, $ingrediente]) }}">
                                                    @csrf
                                                    <button type="submit" class="text-xs text-red-600 hover:text-red-700">Quitar</button>
                                                </form>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <form method="POST" action="{{ route('business.recipe.store', $producto) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <div class="grow">
                                    <label for="ri-insumo-{{ $producto->id }}" class="mb-1 block text-xs text-gray-600">Insumo</label>
                                    <select id="ri-insumo-{{ $producto->id }}" name="inventory_item_id" required class="block w-full rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                                        @foreach ($insumos as $insumo)
                                            <option value="{{ $insumo->id }}">{{ $insumo->name }} ({{ $insumo->base_unit->value }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="w-28">
                                    <label for="ri-cant-{{ $producto->id }}" class="mb-1 block text-xs text-gray-600">Cantidad</label>
                                    <input id="ri-cant-{{ $producto->id }}" name="quantity" type="number" step="0.001" min="0.001" required
                                        class="block w-full rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                                </div>
                                <button type="submit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Añadir</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach

    {{-- Si la validación falló dentro de un modal, se vuelve a abrir ese
         mismo. old() nunca llega crudo a un selector: se valida la forma. --}}
    <script>
        window.addEventListener('load', () => {
            const modal = @json(old('_modal'));
            if (typeof modal === 'string' && /^modal-item-\d+$/.test(modal) && window.HSOverlay) {
                setTimeout(() => window.HSOverlay.open(document.querySelector('#' + modal)), 60);
            }
        });
    </script>
@endsection
