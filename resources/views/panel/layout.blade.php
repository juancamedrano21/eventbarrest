<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Panel') — EventBarRest</title>
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>
<body class="h-full bg-neutral-950 text-neutral-200 antialiased">
    <header class="sticky top-0 z-40 border-b border-neutral-800 bg-neutral-950/90 backdrop-blur">
        <div class="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4">
            <a href="/panel" class="text-sm font-semibold tracking-wide text-white">EventBarRest</a>
            <span class="rounded-full border border-sky-900 bg-sky-950 px-2 py-0.5 text-xs text-sky-300">panel nuevo</span>
            <nav class="ml-auto flex items-center gap-3 text-sm text-neutral-400">
                <a href="/app" class="hover:text-white">Panel clásico</a>
                <span class="text-neutral-700">·</span>
                <span>{{ auth()->user()?->name }}</span>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg border border-teal-900 bg-teal-950 px-4 py-3 text-sm text-teal-300">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-900 bg-red-950 px-4 py-3 text-sm text-red-300">
                <ul class="list-disc pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
