@extends('business.layout')

@section('title', 'Equipo')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Equipo</h1>
            <p class="mt-1 text-sm text-gray-500">Quién entra al sistema y qué puede hacer.</p>
        </div>
        <button type="button" data-hs-overlay="#modal-usuario" aria-haspopup="dialog"
            class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Nuevo usuario
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-5 py-3 font-medium">Nombre</th>
                    <th class="px-5 py-3 font-medium">Correo</th>
                    <th class="px-5 py-3 font-medium">Usuario del POS</th>
                    <th class="px-5 py-3 font-medium">Rol</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($equipo as $fila)
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">
                            {{ $fila['user']->name }}
                            @if ($fila['esUnoMismo'])
                                <span class="ml-1 text-xs font-normal text-gray-400">(tú)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-600">{{ $fila['user']->email }}</td>
                        <td class="px-5 py-3">
                            @if ($fila['user']->username)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-700">{{ $fila['user']->username }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $fila['rol'] }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button type="button" data-hs-overlay="#modal-usuario-{{ $fila['user']->id }}" aria-haspopup="dialog"
                                class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        El equipo entra con su correo; quien está en la caja puede entrar con un usuario corto, más rápido de teclear en una tableta.
    </p>

    {{-- Alta --}}
    <div id="modal-usuario" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-lg">
            <form method="POST" action="{{ route('business.team.store') }}" class="rounded-xl border border-gray-200 bg-white shadow-lg">
                @csrf
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="font-medium text-gray-900">Nuevo usuario</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="u-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                        <input id="u-nombre" name="name" value="{{ old('name') }}" required maxlength="255"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="u-email" class="mb-1.5 block text-sm text-gray-700">Correo</label>
                        <input id="u-email" name="email" type="email" value="{{ old('email') }}" required
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="u-username" class="mb-1.5 block text-sm text-gray-700">Usuario del POS <span class="text-gray-400">(opcional)</span></label>
                        <input id="u-username" name="username" value="{{ old('username') }}" maxlength="30"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm focus:border-gray-400 focus:outline-none">
                        <p class="mt-1.5 text-xs text-gray-500">Sin arroba: es lo que teclea el cajero en la tableta.</p>
                    </div>
                    <div>
                        <label for="u-password" class="mb-1.5 block text-sm text-gray-700">Contraseña</label>
                        <input id="u-password" name="password" type="password" required minlength="8" autocomplete="new-password"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="u-rol" class="mb-1.5 block text-sm text-gray-700">Rol</label>
                        <select id="u-rol" name="role" required class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            @foreach ($roles as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(old('role') === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button type="button" data-hs-overlay="#modal-usuario" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Crear</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edición, una por usuario --}}
    @foreach ($equipo as $fila)
        <div id="modal-usuario-{{ $fila['user']->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="hs-overlay-open:opacity-100 hs-overlay-open:duration-300 opacity-0 transition-all m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-lg">
                <div class="rounded-xl border border-gray-200 bg-white shadow-lg">
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h3 class="font-medium text-gray-900">{{ $fila['user']->name }}</h3>
                    </div>
                    <form method="POST" action="{{ route('business.team.update', $fila['user']) }}">
                        @csrf
                        <div class="space-y-4 p-5">
                            <div>
                                <label for="e-nombre-{{ $fila['user']->id }}" class="mb-1.5 block text-sm text-gray-700">Nombre</label>
                                <input id="e-nombre-{{ $fila['user']->id }}" name="name" value="{{ $fila['user']->name }}" required maxlength="255"
                                    class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            </div>
                            <div>
                                <label for="e-email-{{ $fila['user']->id }}" class="mb-1.5 block text-sm text-gray-700">Correo</label>
                                <input id="e-email-{{ $fila['user']->id }}" name="email" type="email" value="{{ $fila['user']->email }}" required
                                    class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            </div>
                            <div>
                                <label for="e-username-{{ $fila['user']->id }}" class="mb-1.5 block text-sm text-gray-700">Usuario del POS</label>
                                <input id="e-username-{{ $fila['user']->id }}" name="username" value="{{ $fila['user']->username }}" maxlength="30"
                                    class="block w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm focus:border-gray-400 focus:outline-none">
                            </div>
                            <div>
                                <label for="e-password-{{ $fila['user']->id }}" class="mb-1.5 block text-sm text-gray-700">Nueva contraseña</label>
                                <input id="e-password-{{ $fila['user']->id }}" name="password" type="password" minlength="8" autocomplete="new-password"
                                    class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <p class="mt-1.5 text-xs text-gray-500">Déjalo vacío para no cambiarla.</p>
                            </div>
                            <div>
                                <label for="e-rol-{{ $fila['user']->id }}" class="mb-1.5 block text-sm text-gray-700">Rol</label>
                                <select id="e-rol-{{ $fila['user']->id }}" name="role" required @disabled($fila['esUltimoDueno'])
                                    class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500">
                                    @foreach ($roles as $valor => $etiqueta)
                                        <option value="{{ $valor }}" @selected($fila['rolNombre'] === $valor)>{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                                @if ($fila['esUltimoDueno'])
                                    {{-- Deshabilitado no envía valor: se manda aparte para
                                         que el formulario siga siendo válido. --}}
                                    <input type="hidden" name="role" value="{{ $fila['rolNombre'] }}">
                                    <p class="mt-1.5 text-xs text-amber-700">Es la única cuenta de dueño. Nombra otro dueño antes de cambiarle el rol.</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                            <button type="button" data-hs-overlay="#modal-usuario-{{ $fila['user']->id }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
                        </div>
                    </form>

                    @unless ($fila['esUltimoDueno'] || $fila['esUnoMismo'])
                        <form method="POST" action="{{ route('business.team.destroy', $fila['user']) }}"
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
