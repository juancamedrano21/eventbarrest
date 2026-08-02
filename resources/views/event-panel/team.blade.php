@extends($panelLayout)

@section('title', 'Equipo')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Equipo de la cuenta</h1>
            <p class="mt-1 text-sm text-gray-500">
                Quien administra eventos, comercios y reportes. El personal de cada comercio se gestiona desde su propio perfil.
            </p>
        </div>
        <button type="button" data-hs-overlay="#modal-miembro" aria-haspopup="dialog"
            class="rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sky-500">Nuevo usuario</button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-5 py-3 font-medium">Nombre</th>
                    <th class="px-5 py-3 font-medium">Correo</th>
                    <th class="px-5 py-3 font-medium">Rol</th>
                    <th class="px-5 py-3 font-medium">Alta</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($equipo as $fila)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 font-medium text-gray-800">
                            {{ $fila['user']->name }}
                            @if ($fila['esUnoMismo'])
                                <span class="ml-1 text-xs font-normal text-gray-400">(tú)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-600">{{ $fila['user']->email }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-800">{{ $fila['rol'] }}</span>
                        </td>
                        <td class="px-5 py-3 text-gray-500">{{ $fila['user']->created_at?->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-right">
                            <button type="button" data-hs-overlay="#modal-miembro-{{ $fila['user']->id }}" aria-haspopup="dialog"
                                class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">Sin equipo todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Alta --}}
    <div id="modal-miembro" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('event-panel.team.store') }}"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                @csrf
                <h3 class="mb-4 font-medium text-gray-800">Nuevo usuario de la cuenta</h3>
                <div class="space-y-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Nombre" required maxlength="255"
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="email" type="email" value="{{ old('email') }}" placeholder="Correo" required
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="username" value="{{ old('username') }}" placeholder="Usuario del POS (opcional)" maxlength="30"
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 font-mono text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <input name="password" type="password" placeholder="Contraseña" required minlength="8" autocomplete="new-password"
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                    <select name="role" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @foreach ($roles as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(old('role') === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-miembro">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Crear</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edición --}}
    @foreach ($equipo as $fila)
        <div id="modal-miembro-{{ $fila['user']->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
                <div class="rounded-xl border border-gray-200 bg-white shadow-xl">
                    <form method="POST" action="{{ route('event-panel.team.update', $fila['user']) }}" class="p-5">
                        @csrf
                        <h3 class="mb-4 font-medium text-gray-800">{{ $fila['user']->name }}</h3>
                        <div class="space-y-3">
                            <input name="name" value="{{ $fila['user']->name }}" required maxlength="255"
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            <input name="email" type="email" value="{{ $fila['user']->email }}" required
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            <input name="username" value="{{ $fila['user']->username }}" placeholder="Usuario del POS (opcional)" maxlength="30"
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 font-mono text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                            <input name="password" type="password" placeholder="Nueva contraseña (vacío = no cambia)" minlength="8" autocomplete="new-password"
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-sky-500 focus:ring-sky-500">
                            <select name="role" required @disabled($fila['esUltimoDueno'])
                                class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500 disabled:bg-gray-50 disabled:text-gray-500">
                                @foreach ($fila['roles'] as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected($fila['rolNombre'] === $valor)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            @if ($fila['esUltimoDueno'])
                                {{-- Deshabilitado no envía valor: se manda aparte para
                                     que el formulario siga siendo válido. --}}
                                <input type="hidden" name="role" value="{{ $fila['rolNombre'] }}">
                                <p class="text-xs text-amber-700">Es la única cuenta de dueño. Nombra otro dueño antes de cambiarle el rol.</p>
                            @endif
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-miembro-{{ $fila['user']->id }}">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                        </div>
                    </form>

                    @unless ($fila['esUltimoDueno'] || $fila['esUnoMismo'])
                        <form method="POST" action="{{ route('event-panel.team.destroy', $fila['user']) }}"
                            onsubmit="return confirm('¿Eliminar a {{ $fila['user']->name }}? Perderá el acceso al sistema.')"
                            class="border-t border-gray-200 px-5 py-3">
                            @csrf
                            <button type="submit" class="text-sm text-red-600 hover:text-red-700">Eliminar usuario</button>
                        </form>
                    @endunless
                </div>
            </div>
        </div>
    @endforeach
@endsection
