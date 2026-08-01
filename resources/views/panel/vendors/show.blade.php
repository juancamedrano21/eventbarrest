@extends('panel.layout')

@section('title', $vendor->name)

@section('content')
    {{-- Encabezado del perfil --}}
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-widest text-neutral-500">Comercio</p>
            <h1 class="mt-1 text-2xl font-semibold text-white">{{ $vendor->name }}</h1>
            <div class="mt-2 flex flex-wrap gap-4 text-sm text-neutral-400">
                <span>RNC: {{ $vendor->rnc ?? '—' }}</span>
                <span>Contacto: {{ $vendor->contact_name ?? '—' }} {{ $vendor->contact_phone ? '· '.$vendor->contact_phone : '' }}</span>
            </div>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-medium
            {{ $vendor->status->value === 'active' ? 'bg-teal-950 text-teal-300 border border-teal-900' : 'bg-amber-950 text-amber-300 border border-amber-900' }}">
            {{ $vendor->status->getLabel() }}
        </span>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Equipo --}}
        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Equipo del comercio</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-usuario" data-hs-overlay="#modal-usuario">
                    Nuevo usuario
                </button>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($vendor->users as $member)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-neutral-200">{{ $member->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $member->email }}
                                @if ($member->username) · POS: <span class="text-neutral-400">{{ $member->username }}</span> @endif
                            </p>
                        </div>
                        <span class="rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs text-neutral-300">
                            {{ $roleLabels[$member->roles->first()?->name] ?? '—' }}
                        </span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Sin equipo: crea su encargado — él montará el catálogo.</li>
                @endforelse
            </ul>
        </section>

        {{-- Eventos --}}
        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Eventos en los que participa</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-invitar" data-hs-overlay="#modal-invitar">
                    Invitar a evento
                </button>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($participations as $event)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-neutral-200">{{ $event->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $event->starts_at->format('d M Y, H:i') }}</p>
                        </div>
                        <span class="text-xs text-neutral-400">Comisión {{ number_format($event->pivot->commission_bps / 100, 2) }} %</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Aún no participa en ningún evento.</li>
                @endforelse
            </ul>
        </section>

        {{-- Puestos --}}
        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Puestos de venta</h2>
                <button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-puesto" data-hs-overlay="#modal-puesto">
                    Nuevo puesto
                </button>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($outlets as $outlet)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-neutral-200">{{ $outlet->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $outlet->event?->name }}</p>
                        </div>
                        <span class="rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs text-neutral-300">{{ $outlet->kind->getLabel() }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Sin puestos: invítalo a un evento y asígnale su barra o cocina.</li>
                @endforelse
            </ul>
        </section>

        {{-- Catálogo (solo lectura) --}}
        <section class="rounded-xl border border-neutral-800 bg-neutral-900">
            <header class="border-b border-neutral-800 px-5 py-4">
                <h2 class="font-medium text-white">Catálogo <span class="ml-1 text-xs font-normal text-neutral-500">lo administra el comercio</span></h2>
            </header>
            <ul class="divide-y divide-neutral-800">
                @forelse ($products as $product)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="text-neutral-200">{{ $product->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $product->category?->name }}</p>
                        </div>
                        <span class="text-neutral-300">RD$ {{ number_format($product->price_cents / 100, 2) }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-neutral-500">Su encargado aún no monta el catálogo.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Modal: nuevo usuario --}}
    <div id="modal-usuario" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.users.store', $vendor) }}"
                class="rounded-xl border border-neutral-700 bg-neutral-900 p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-white">Nuevo usuario de {{ $vendor->name }}</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="username" value="{{ old('username') }}" placeholder="Usuario del POS (opcional)" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="email" type="email" value="{{ old('email') }}" placeholder="Correo" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <input name="password" type="password" placeholder="Contraseña" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <select name="role" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                        @foreach ($vendorRoles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300" data-hs-overlay="#modal-usuario">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: invitar a evento --}}
    <div id="modal-invitar" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.invite', $vendor) }}"
                class="rounded-xl border border-neutral-700 bg-neutral-900 p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-white">Invitar a un evento</h3>
                <div class="space-y-3">
                    <select name="event_id" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                        @forelse ($invitableEvents as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @empty
                            <option value="" disabled>Ya participa en todos los eventos</option>
                        @endforelse
                    </select>
                    <input name="commission" type="number" step="0.01" min="0" max="100" value="{{ old('commission', 0) }}"
                        placeholder="Comisión %" class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300" data-hs-overlay="#modal-invitar">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Invitar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: nuevo puesto --}}
    <div id="modal-puesto" class="hs-overlay hidden size-full fixed top-0 start-0 z-60 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('panel.vendors.outlets.store', $vendor) }}"
                class="rounded-xl border border-neutral-700 bg-neutral-900 p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-white">Nuevo puesto de venta</h3>
                <div class="space-y-3">
                    <select name="event_id" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                        @foreach ($participations as $event)
                            <option value="{{ $event->id }}">{{ $event->name }}</option>
                        @endforeach
                    </select>
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre del puesto" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200 placeholder-neutral-500">
                    <select name="kind" required class="w-full rounded-lg border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-200">
                        <option value="bar">Barra</option>
                        <option value="kitchen">Cocina</option>
                        <option value="mixed">Mixta</option>
                    </select>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300" data-hs-overlay="#modal-puesto">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear puesto</button>
                </div>
            </form>
        </div>
    </div>
@endsection
