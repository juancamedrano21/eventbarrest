<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Mi comercio') — EventBarRest</title>
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>
<body class="h-full bg-gray-50 text-gray-800 antialiased">

    {{-- Barra del comercio: sin sidebar — este mundo es UNA sola casa --}}
    <header class="sticky top-0 z-40 border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:px-6">
            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-sky-600 text-sm font-bold text-white">
                {{ mb_substr((string) auth()->user()?->vendor?->name, 0, 1) }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-800">{{ auth()->user()?->vendor?->name }}</p>
                <p class="truncate text-xs text-gray-500">{{ auth()->user()?->tenant?->name }}</p>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <a href="/pos" target="_blank" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">POS</a>
                <span class="hidden text-sm text-gray-500 sm:block">{{ auth()->user()?->name }}</span>
                <form method="POST" action="{{ route('filament.app.auth.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">Salir</button>
                </form>
            </div>
        </div>
    </header>

    <main>
        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            @if (session('status'))
                <div class="mb-6 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-disc pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</body>
</html>
