<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — EventBarRest</title>
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>
<body class="h-full bg-gray-50 text-gray-800 antialiased">
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center gap-2">
                <span class="grid size-9 place-items-center rounded-lg bg-sky-600 text-sm font-bold text-white">EB</span>
                <div>
                    <p class="font-semibold text-gray-800">EventBarRest</p>
                    <p class="text-xs text-gray-500">Bares, restaurantes y eventos</p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-2xs">
                <h1 class="text-lg font-semibold text-gray-800">Entrar</h1>
                <p class="mt-1 text-sm text-gray-500">Con tu correo o tu usuario.</p>

                @if ($errors->any())
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-5 space-y-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-gray-700">Correo o usuario</span>
                        <input name="usuario" value="{{ old('usuario') }}" required autofocus
                            autocomplete="username" autocapitalize="none" spellcheck="false"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-gray-700">Contraseña</span>
                        <input name="password" type="password" required autocomplete="current-password"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="recordarme" value="1" class="rounded border-gray-300 text-sky-600 focus:ring-sky-500">
                        Mantener la sesión abierta
                    </label>

                    <button type="submit" class="w-full rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500">
                        Entrar
                    </button>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-gray-500">
                ¿Vas a cobrar en una caja? El punto de venta está en
                <a href="/pos" class="font-medium text-sky-700 hover:underline">/pos</a>.
            </p>
        </div>
    </main>
</body>
</html>
