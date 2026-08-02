{{--
    El chrome de /business: la casa del bar o restaurante independiente.

    Layout FIJO, sin la indirección $panelLayout de /event-panel: ese salto
    apunta a un tema comprado que vive fuera de git, así que las pantallas se
    verían distintas en la máquina de quien lo tiene y en CI. Una puerta, un
    layout.

    El menú se dibuja desde permisos: quien no administra sucursales no ve
    «Sucursales». Es cortesía, no seguridad — cada pantalla vuelve a exigir
    su permiso por su cuenta.
--}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Negocio') — EventBarRest</title>
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>
<body class="h-full bg-gray-50 text-gray-800 antialiased">

@php
    $usuario = auth()->user();
    $actual = request()->route()?->getName() ?? '';

    $secciones = [
        ['ruta' => 'business.home', 'url' => route('business.home'), 'label' => 'Resumen', 'permiso' => null,
         'icono' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75'],
        ['ruta' => 'business.menu', 'url' => route('business.menu'), 'label' => 'Menú', 'permiso' => 'catalog.manage',
         'icono' => 'M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5'],
        ['ruta' => 'business.inventory', 'url' => route('business.inventory'), 'label' => 'Inventario', 'permiso' => 'inventory.manage',
         'icono' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
        ['ruta' => 'business.sales', 'url' => route('business.sales.index'), 'label' => 'Ventas', 'permiso' => 'reports.view_unit',
         'icono' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z'],
        ['ruta' => 'business.cash', 'url' => route('business.cash.index'), 'label' => 'Caja', 'permiso' => 'reports.view_unit',
         'icono' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3'],
        ['ruta' => 'business.branches', 'url' => route('business.branches.index'), 'label' => 'Sucursales', 'permiso' => 'branches.manage',
         'icono' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z'],
        ['ruta' => 'business.team', 'url' => route('business.team.index'), 'label' => 'Equipo', 'permiso' => 'users.manage',
         'icono' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
        ['ruta' => 'business.settings', 'url' => route('business.settings.edit'), 'label' => 'Ajustes', 'permiso' => 'fiscal.manage',
         'icono' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z'],
    ];

    $visibles = array_values(array_filter(
        $secciones,
        fn (array $s): bool => $s['permiso'] === null || $usuario?->can($s['permiso']),
    ));
@endphp

    {{-- Topbar móvil --}}
    <div class="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 lg:hidden">
        <button type="button" class="text-gray-500" aria-haspopup="dialog" aria-expanded="false"
            aria-controls="business-sidebar" data-hs-overlay="#business-sidebar">
            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="text-sm font-semibold">@yield('title', 'Negocio')</span>
    </div>

    {{-- Sidebar --}}
    <aside id="business-sidebar" class="hs-overlay fixed inset-y-0 start-0 z-50 hidden w-64 -translate-x-full transform border-e border-gray-200 bg-white transition-all duration-300 hs-overlay-open:translate-x-0 lg:bottom-0 lg:end-auto lg:block lg:translate-x-0" role="dialog" tabindex="-1">
        <div class="flex h-full flex-col">
            <div class="flex items-center gap-2.5 px-5 pt-5 pb-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-gray-900 text-sm font-bold text-white">
                    {{ mb_strtoupper(mb_substr((string) $usuario?->tenant?->name, 0, 2)) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-900">{{ $usuario?->tenant?->name }}</p>
                    <p class="text-xs text-gray-500">Negocio</p>
                </div>
            </div>

            <nav class="flex flex-col gap-0.5 px-3 py-2">
                @foreach ($visibles as $seccion)
                    @php($activa = str_starts_with($actual, $seccion['ruta']))
                    <a href="{{ $seccion['url'] }}"
                        @if ($activa) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition {{ $activa ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $seccion['icono'] }}"/></svg>
                        {{ $seccion['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto space-y-1 border-t border-gray-200 px-3 py-4">
                @if ($usuario?->canOperateThePos())
                    <a href="/pos" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-gray-900">
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                        Abrir el POS
                    </a>
                @endif
                <div class="flex items-center gap-3 px-3 py-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600">
                        {{ mb_substr((string) $usuario?->name, 0, 1) }}
                    </span>
                    <div class="min-w-0 grow">
                        <p class="truncate text-sm font-medium text-gray-800">{{ $usuario?->name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $usuario?->email }}</p>
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
