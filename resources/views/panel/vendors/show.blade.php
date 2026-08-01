@extends($panelLayout)

@section('title', $vendor->name)

@section('content')
    {{-- Encabezado del perfil --}}
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            @if ($vendor->logo_path)
                <img src="{{ Storage::url($vendor->logo_path) }}" alt="Logo" class="size-14 rounded-xl border border-gray-200 object-cover">
            @else
                <span class="grid size-14 place-items-center rounded-xl border border-gray-200 bg-gray-100 text-lg font-semibold text-gray-500">{{ mb_substr($vendor->name, 0, 1) }}</span>
            @endif
            <div>
            <p class="text-xs uppercase tracking-widest text-gray-500">Comercio</p>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">{{ $vendor->name }}</h1>
            <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                <span>RNC: {{ $vendor->rnc ?? '—' }}</span>
                <span>Contacto: {{ $vendor->contact_name ?? '—' }} {{ $vendor->contact_phone ? '· '.$vendor->contact_phone : '' }}</span>
                @if ($vendor->vendorType)<span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $vendor->vendorType->name }}</span>@endif
                @if ($vendor->foodType)<span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $vendor->foodType->name }}</span>@endif
            </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="rounded-full px-3 py-1 text-xs font-medium
                {{ $vendor->status->value === 'active' ? 'bg-teal-100 text-teal-800' : 'bg-amber-100 text-amber-800' }}">
                {{ $vendor->status->getLabel() }}
            </span>
            <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 hover:text-gray-800"
                aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-editar" data-hs-overlay="#modal-editar">
                Editar datos
            </button>
        </div>
    </div>

    
    {{-- Pestañas --}}
    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-gray-200" role="tablist" aria-orientation="horizontal">
        @foreach (['resumen' => 'Resumen', 'menu' => 'Menú', 'ventas' => 'Ventas', 'transacciones' => 'Transacciones', 'inventario' => 'Inventario', 'usuarios' => 'Usuarios', 'config' => 'Configuraciones'] as $id => $label)
            <button type="button" id="tab-{{ $id }}-item" data-hs-tab="#tab-{{ $id }}" aria-controls="tab-{{ $id }}" role="tab"
                class="{{ $loop->first ? 'active ' : '' }}hs-tab-active:border-sky-600 hs-tab-active:text-sky-600 whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm text-gray-500 hover:text-gray-700">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    {{-- Tab: Resumen --}}
    <div id="tab-resumen" role="tabpanel" aria-labelledby="tab-resumen-item">
<div class="grid gap-6 lg:grid-cols-2">
        {{-- Eventos --}}
        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Eventos en los que participa</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-invitar" data-hs-overlay="#modal-invitar">
                    Invitar a evento
                </button>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($participations as $event)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $event->name }}</p>
                            <p class="text-xs text-gray-500">{{ $event->starts_at->format('d M Y, H:i') }}</p>
                        </div>
                        <span class="text-xs text-gray-500">Comisión {{ number_format($event->pivot->commission_bps / 100, 2) }} %</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Aún no participa en ningún evento.</li>
                @endforelse
            </ul>
        </section>

        {{-- Puestos --}}
        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Puestos de venta</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-puesto" data-hs-overlay="#modal-puesto">
                    Nuevo puesto
                </button>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($outlets as $outlet)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $outlet->name }}</p>
                            <p class="text-xs text-gray-500">{{ $outlet->event?->name }}</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $outlet->kind->getLabel() }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Sin puestos: invítalo a un evento y asígnale su barra o cocina.</li>
                @endforelse
            </ul>
        </section>


    </div>
    </div>

    {{-- Tab: Menú --}}
    <div id="tab-menu" class="hidden" role="tabpanel" aria-labelledby="tab-menu-item">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">El menú del comercio, clasificado en <span class="font-medium text-gray-700">Alimentos</span> (salen de cocina) y <span class="font-medium text-gray-700">Bebidas</span> (salen de barra).</p>
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
                        <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-gray-800 {{ $product->active ? '' : 'line-through opacity-60' }}">{{ $product->name }}</p>
                                <p class="text-xs text-gray-500">
                                    @if ($product->type->value === 'recipe')
                                        Receta: {{ $product->recipeItems->count() }} ingrediente(s)
                                    @elseif ($product->inventoryItem)
                                        Descuenta: {{ $product->inventoryItem->name }}
                                    @else
                                        Sin control de inventario
                                    @endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('panel.vendors.products.update', [$vendor, $product]) }}" class="flex shrink-0 items-center gap-2">
                                @csrf
                                <div class="flex items-center rounded-lg border border-gray-200">
                                    <span class="px-2 text-xs text-gray-500">RD$</span>
                                    <input name="price" value="{{ number_format($product->price_cents / 100, 2, '.', '') }}"
                                        class="w-20 border-0 bg-transparent py-1.5 pe-2 text-right text-sm text-gray-800 focus:ring-0">
                                </div>
                                <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">Guardar</button>
                                <button type="submit" name="active" value="{{ $product->active ? 0 : 1 }}"
                                    class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs {{ $product->active ? 'text-amber-700' : 'text-teal-700' }} hover:bg-gray-50">
                                    {{ $product->active ? 'Desactivar' : 'Activar' }}
                                </button>
                            </form>
                            @if ($product->type->value === 'recipe')
                                <button type="button" class="shrink-0 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs text-sky-700 hover:bg-sky-100"
                                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-receta-{{ $product->id }}" data-hs-overlay="#modal-receta-{{ $product->id }}">
                                    Receta
                                </button>
                            @endif
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

        {{-- Modales de receta (escandallo) por producto --}}
        @foreach ($menuCategories as $categoria)
            @foreach ($categoria->products->where('type.value', 'recipe') as $product)
                <div id="modal-receta-{{ $product->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
                    <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                            <h3 class="mb-1 font-medium text-gray-800">Receta de {{ $product->name }}</h3>
                            <p class="mb-4 text-xs text-gray-500">Lo que cada venta descuenta del inventario, en la unidad base de cada insumo.</p>

                            <ul class="mb-4 divide-y divide-gray-200 rounded-lg border border-gray-200">
                                @forelse ($product->recipeItems as $ingrediente)
                                    <li class="flex items-center justify-between px-3 py-2 text-sm">
                                        <span class="text-gray-800">{{ $ingrediente->inventoryItem?->name }}</span>
                                        <span class="flex items-center gap-2">
                                            <span class="text-gray-500">{{ number_format((float) $ingrediente->quantity, 3) }} {{ $ingrediente->inventoryItem?->base_unit->short() }}</span>
                                            <form method="POST" action="{{ route('panel.vendors.recipe.destroy', [$vendor, $product, $ingrediente]) }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-red-600 hover:text-red-700">Quitar</button>
                                            </form>
                                        </span>
                                    </li>
                                @empty
                                    <li class="px-3 py-3 text-sm text-gray-500">Sin ingredientes: este producto aún no descuenta nada.</li>
                                @endforelse
                            </ul>

                            <form method="POST" action="{{ route('panel.vendors.recipe.store', [$vendor, $product]) }}" class="flex gap-2">
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

                            <div class="mt-4 flex justify-end">
                                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-receta-{{ $product->id }}">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>

    {{-- Tab: Ventas --}}
    <div id="tab-ventas" class="hidden" role="tabpanel" aria-labelledby="tab-ventas-item">
        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas de hoy</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesToday / 100, 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas históricas</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesTotal / 100, 2) }}</p>
            </div>
        </div>
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr><th class="px-5 py-3">Orden</th><th class="px-5 py-3">Puesto</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Total</th><th class="px-5 py-3">Cobrada</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($recentOrders as $order)
                        <tr>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ Str::limit($order->client_ref, 14) }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $order->operatingUnit?->name }}</td>
                            <td class="px-5 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs {{ $order->status->value === 'paid' ? 'bg-teal-100 text-teal-800' : ($order->status->value === 'void' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600') }}">{{ $order->status->getLabel() }}</span></td>
                            <td class="px-5 py-3 text-right text-gray-800">RD$ {{ number_format($order->total_cents / 100, 2) }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $order->paid_at?->format('d M, H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">Sin ventas todavía: llegarán desde el POS de sus cajeros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Tab: Transacciones --}}
    <div id="tab-transacciones" class="hidden" role="tabpanel" aria-labelledby="tab-transacciones-item">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr><th class="px-5 py-3">Orden</th><th class="px-5 py-3">Método</th><th class="px-5 py-3 text-right">Cobrado</th><th class="px-5 py-3 text-right">Recibido</th><th class="px-5 py-3 text-right">Vuelto</th><th class="px-5 py-3">Fecha</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($recentPayments as $payment)
                        <tr>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ Str::limit($payment->order?->client_ref, 14) }}</td>
                            <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $payment->method->getLabel() }}</span></td>
                            <td class="px-5 py-3 text-right text-gray-800">RD$ {{ number_format($payment->amount_cents / 100, 2) }}</td>
                            <td class="px-5 py-3 text-right text-gray-500">{{ $payment->tendered_cents !== null ? 'RD$ '.number_format($payment->tendered_cents / 100, 2) : '—' }}</td>
                            <td class="px-5 py-3 text-right text-gray-500">{{ $payment->change_cents ? 'RD$ '.number_format($payment->change_cents / 100, 2) : '—' }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $payment->created_at->format('d M, H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">Sin cobros registrados todavía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

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
    </div>

    {{-- Tab: Usuarios --}}
    <div id="tab-usuarios" class="hidden" role="tabpanel" aria-labelledby="tab-usuarios-item">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <h2 class="font-medium text-gray-800">Equipo del comercio</h2>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $vendor->users->count() }}</span>
                </div>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-usuario" data-hs-overlay="#modal-usuario">
                    Nuevo usuario
                </button>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Usuario</th>
                            <th class="px-5 py-3 font-medium">Usuario POS</th>
                            <th class="px-5 py-3 font-medium">Rol</th>
                            <th class="px-5 py-3 font-medium">Alta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($vendor->users as $member)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <div class="flex items-center gap-x-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-sky-100 text-sm font-semibold text-sky-700">
                                            {{ mb_substr($member->name, 0, 1) }}
                                        </span>
                                        <div>
                                            <p class="font-medium text-gray-800">{{ $member->name }}</p>
                                            <p class="text-xs text-gray-500">{{ $member->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    @if ($member->username)
                                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 font-mono text-xs text-gray-600">{{ $member->username }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-800">
                                        {{ $roleLabels[$member->roles->first()?->name] ?? '—' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $member->created_at->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-gray-500">
                                    Sin equipo: crea su encargado — él montará el catálogo y sus cajeros venderán en el POS.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Tab: Configuraciones --}}
    <div id="tab-config" class="hidden" role="tabpanel" aria-labelledby="tab-config-item">
        <form method="POST" action="{{ route('panel.vendors.update', $vendor) }}" enctype="multipart/form-data"
            class="max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-2xs">
            @csrf
            <h2 class="mb-4 font-medium text-gray-800">Configuración del comercio</h2>
            <div class="space-y-4">
                <div class="flex items-center gap-4">
                    @if ($vendor->logo_path)
                        <img src="{{ Storage::url($vendor->logo_path) }}" alt="Logo" class="size-16 rounded-xl border border-gray-200 object-cover">
                    @else
                        <span class="grid size-16 place-items-center rounded-xl border border-dashed border-gray-300 text-xs text-gray-400">Sin logo</span>
                    @endif
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-gray-500">Logo del comercio (PNG/JPG, máx. 2 MB)</span>
                        <input type="file" name="logo" accept="image/*" class="block w-full text-sm text-gray-500 file:me-4 file:rounded-lg file:border-0 file:bg-sky-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-sky-500">
                    </label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Nombre</span>
                    <input name="name" value="{{ old('name', $vendor->name) }}" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Tipo de negocio</span>
                        <select name="vendor_type_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                            <option value="">Sin clasificar</option>
                            @foreach ($vendorTypes as $id => $name)
                                <option value="{{ $id }}" @selected((int) old('vendor_type_id', $vendor->vendor_type_id) === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Tipo de comida</span>
                        <select name="food_type_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                            <option value="">Sin clasificar</option>
                            @foreach ($foodTypes as $id => $name)
                                <option value="{{ $id }}" @selected((int) old('food_type_id', $vendor->food_type_id) === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">RNC / Cédula</span>
                        <input name="rnc" value="{{ old('rnc', $vendor->rnc) }}" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                    </label>
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Estado</span>
                        <select name="status" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                            @foreach (['draft' => 'En alta', 'active' => 'Activo', 'suspended' => 'Suspendido'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $vendor->status->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Persona de contacto</span>
                        <input name="contact_name" value="{{ old('contact_name', $vendor->contact_name) }}" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                    </label>
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">Teléfono</span>
                        <input name="contact_phone" value="{{ old('contact_phone', $vendor->contact_phone) }}" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                    </label>
                </div>
                <p class="text-xs text-gray-500">Suspender corta el acceso de todo su personal, incluido el POS.</p>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500">Guardar configuración</button>
            </div>
        </form>
    </div>

{{-- Modal: nueva categoría --}}
    <div id="modal-categoria" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.categories.store', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
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
            <form method="POST" action="{{ route('panel.vendors.products.store', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo producto del menú</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del producto" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
                    <input name="price" type="text" inputmode="decimal" value="{{ old('price') }}" placeholder="Precio (RD$)" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400">
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
                    <select name="inventory_item_id" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="">Sin insumo vinculado (solo para Simple)</option>
                        @foreach ($vendorItems as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Simple + insumo: vende 1, descuenta 1 (ej. cerveza). Con receta: crea el producto y ábrele «Receta» en su fila para armar el escandallo.</p>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600" data-hs-overlay="#modal-producto">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear producto</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: nuevo insumo --}}
    <div id="modal-insumo" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.items.store', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
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
            <form method="POST" action="{{ route('panel.vendors.purchases.store', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Registrar compra</h3>
                <div class="space-y-3">
                    <select name="operating_unit_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                        @forelse ($outlets as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }} — {{ $outlet->event?->name }}</option>
                        @empty
                            <option value="" disabled>Primero crea un puesto</option>
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

    {{-- Modal: editar datos --}}
    <div id="modal-editar" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.update', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Editar {{ $vendor->name }}</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name', $vendor->name) }}" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    <input name="rnc" value="{{ old('rnc', $vendor->rnc) }}" placeholder="RNC / Cédula" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="contact_name" value="{{ old('contact_name', $vendor->contact_name) }}" placeholder="Persona de contacto" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="contact_phone" value="{{ old('contact_phone', $vendor->contact_phone) }}" placeholder="Teléfono" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <select name="status" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @foreach (['draft' => 'En alta', 'active' => 'Activo', 'suspended' => 'Suspendido'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $vendor->status->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Suspender corta el acceso de todo su personal, incluido el POS.</p>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-editar">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: nuevo usuario --}}
    <div id="modal-usuario" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.users.store', $vendor) }}"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo usuario de {{ $vendor->name }}</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="username" value="{{ old('username') }}" placeholder="Usuario del POS (opcional)" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="email" type="email" value="{{ old('email') }}" placeholder="Correo" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="password" type="password" placeholder="Contraseña" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <select name="role" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @foreach ($vendorRoles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-usuario">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: invitar a evento --}}
    <div id="modal-invitar" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.invite', $vendor) }}"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Invitar a un evento</h3>
                <div class="space-y-3">
                    <select name="event_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @forelse ($invitableEvents as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @empty
                            <option value="" disabled>Ya participa en todos los eventos</option>
                        @endforelse
                    </select>
                    <input name="commission" type="number" step="0.01" min="0" max="100" value="{{ old('commission', 0) }}"
                        placeholder="Comisión %" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-invitar">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Invitar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: nuevo puesto --}}
    <div id="modal-puesto" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.outlets.store', $vendor) }}"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo puesto de venta</h3>
                <div class="space-y-3">
                    <select name="event_id" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @foreach ($participations as $event)
                            <option value="{{ $event->id }}">{{ $event->name }}</option>
                        @endforeach
                    </select>
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del puesto" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <select name="kind" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        <option value="bar">Barra</option>
                        <option value="kitchen">Cocina</option>
                        <option value="mixed">Mixta</option>
                    </select>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-puesto">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear puesto</button>
                </div>
            </form>
        </div>
    </div>
@endsection
