@extends($panelLayout)

@section('title', $vendor->name)

@section('content')
    {{-- Encabezado del perfil --}}
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-gray-500">Comercio</p>
            <h1 class="mt-1 text-2xl font-semibold text-gray-800">{{ $vendor->name }}</h1>
            <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                <span>RNC: {{ $vendor->rnc ?? '—' }}</span>
                <span>Contacto: {{ $vendor->contact_name ?? '—' }} {{ $vendor->contact_phone ? '· '.$vendor->contact_phone : '' }}</span>
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

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Equipo --}}
        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Equipo del comercio</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-usuario" data-hs-overlay="#modal-usuario">
                    Nuevo usuario
                </button>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($vendor->users as $member)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $member->name }}</p>
                            <p class="text-xs text-gray-500">{{ $member->email }}
                                @if ($member->username) · POS: <span class="text-gray-500">{{ $member->username }}</span> @endif
                            </p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">
                            {{ $roleLabels[$member->roles->first()?->name] ?? '—' }}
                        </span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Sin equipo: crea su encargado — él montará el catálogo.</li>
                @endforelse
            </ul>
        </section>

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

        {{-- Catálogo (solo lectura) --}}
        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-4">
                <h2 class="font-medium text-gray-800">Catálogo <span class="ml-1 text-xs font-normal text-gray-500">lo administra el comercio</span></h2>
            </header>
            <ul class="divide-y divide-gray-200">
                @forelse ($products as $product)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $product->name }}</p>
                            <p class="text-xs text-gray-500">{{ $product->category?->name }}</p>
                        </div>
                        <span class="text-gray-600">RD$ {{ number_format($product->price_cents / 100, 2) }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Su encargado aún no monta el catálogo.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Modal: editar datos --}}
    <div id="modal-editar" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
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
    <div id="modal-usuario" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
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
    <div id="modal-invitar" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
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
    <div id="modal-puesto" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
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
