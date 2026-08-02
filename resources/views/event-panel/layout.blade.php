<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Panel') — EventBarRest</title>
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>
<body class="h-full bg-gray-50 text-gray-800 antialiased">

    {{-- Topbar móvil --}}
    <div class="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 lg:hidden">
        <button type="button" class="text-gray-500" aria-haspopup="dialog" aria-expanded="false"
            aria-controls="panel-sidebar" data-hs-overlay="#panel-sidebar">
            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="text-sm font-semibold">EventBarRest</span>
    </div>

    {{-- Sidebar --}}
    <aside id="panel-sidebar" class="hs-overlay fixed inset-y-0 start-0 z-50 hidden w-64 -translate-x-full transform border-e border-gray-200 bg-white transition-all duration-300 hs-overlay-open:translate-x-0 lg:bottom-0 lg:end-auto lg:block lg:translate-x-0" role="dialog" tabindex="-1">
        <div class="flex h-full flex-col">
            <div class="flex items-center gap-2 px-6 pt-5 pb-4">
                <span class="grid size-8 place-items-center rounded-lg bg-sky-600 text-sm font-bold text-white">EB</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">EventBarRest</p>
                    <p class="text-xs text-gray-500">{{ auth()->user()?->tenant?->name }}</p>
                </div>
            </div>

            <nav class="flex flex-col gap-1 px-3 py-3">
                @php($current = request()->route()?->getName() ?? '')
                <a href="{{ route('event-panel.vendors.index') }}"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ str_starts_with($current, 'event-panel.vendors') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-100' }}">
                    <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.35m-16.5 11.65V9.35m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72"/></svg>
                    Negocios
                </a>
                <a href="{{ route('event-panel.events.index') }}"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ str_starts_with($current, 'event-panel.events') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-100' }}">
                    <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    Eventos
                </a>
                @can('users.manage')
                    <a href="{{ route('event-panel.team.index') }}"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ str_starts_with($current, 'event-panel.team') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-100' }}">
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                        Equipo
                    </a>
                @endcan
                @can('fiscal.manage')
                    <a href="{{ route('event-panel.settings.edit') }}"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ str_starts_with($current, 'event-panel.settings') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-100' }}">
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        Ajustes
                    </a>
                @endcan
            </nav>

            <div class="mt-auto space-y-1 border-t border-gray-200 px-3 py-4">
                <div class="flex items-center gap-3 px-3 py-2">
                    <span class="grid size-8 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600">
                        {{ mb_substr((string) auth()->user()?->name, 0, 1) }}
                    </span>
                    <div class="min-w-0 grow">
                        <p class="truncate text-sm font-medium text-gray-800">{{ auth()->user()?->name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ auth()->user()?->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Salir" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    {{-- Contenido --}}
    <main class="lg:ps-64">
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
