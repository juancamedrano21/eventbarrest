@extends($panelLayout)

@section('title', $vendor->name)

@section('content')
    {{-- Todo lo del KDS se prepara aquí arriba, en bloque. Nada de la forma
         en línea con paréntesis: se empareja con el primer cierre ajeno del
         archivo y se traga las directivas de en medio. --}}
    @php
        $codigoKds = $vendor->getAttribute('kds_code');

        // Las tabletas se piden desde la vista porque el controlador del
        // perfil no es de esta pantalla. Es la única consulta que este Blade
        // hace por su cuenta; el día que crezca, que suba al controlador.
        $tabletas = \App\Domains\Kitchen\Models\KdsDevice::query()
            ->where('vendor_id', $vendor->id)
            ->with('unit')
            ->orderBy('name')
            ->get();

        // Las apagadas se quedan en la lista, al final: la pregunta que trae
        // aquí al organizador suele ser «¿ya revoqué esa?».
        [$tabletasVivas, $tabletasApagadas] = $tabletas->partition(fn ($t): bool => ! $t->estaRevocada());
        $tabletas = $tabletasVivas->concat($tabletasApagadas);

        $puestosConPin = $outlets->filter(fn ($o): bool => filled($o->getAttribute('kds_pin_hash')))->count();

        $fechaKds = fn ($valor): string => $valor === null
            ? '—'
            : \Illuminate\Support\Carbon::parse((string) $valor)->timezone($tz)->locale('es')->translatedFormat('j \d\e F');

        $horaKds = fn ($valor): string => $valor === null
            ? 'Nunca'
            : \Illuminate\Support\Carbon::parse((string) $valor)->timezone($tz)->format('d M, h:i a');

        // Null significa bloqueo ya vencido: la fecha se queda escrita en la
        // fila, y mostrarla como un bloqueo vigente asustaría sin motivo.
        $bloqueoKds = function ($outlet) use ($tz) {
            $valor = $outlet->getAttribute('kds_pin_locked_until');

            if ($valor === null) {
                return null;
            }

            $hasta = \Illuminate\Support\Carbon::parse((string) $valor)->timezone($tz);

            return $hasta->isFuture() ? $hasta : null;
        };

        $nombreDelPuesto = fn (int $id): string => $outlets->firstWhere('id', $id)?->name ?? 'Puesto';
    @endphp

    {{-- El PIN en claro, una sola vez. Va arriba del todo y fuera de las
         pestañas a propósito: la pantalla recuerda la última pestaña abierta,
         así que dentro de una podría no llegar a verse nunca. --}}
    @if (session('kdsPins'))
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4">
            <p class="font-medium text-amber-900">
                Apunta {{ count(session('kdsPins')) === 1 ? 'este PIN ahora' : 'estos PIN ahora' }}: no se vuelven a mostrar.
            </p>
            <ul class="mt-3 space-y-2">
                @foreach (session('kdsPins') as $puestoId => $pin)
                    <li class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-amber-900">{{ $nombreDelPuesto((int) $puestoId) }}</span>
                        <code class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 font-mono text-lg tracking-[0.35em] text-gray-800">{{ $pin }}</code>
                        <button type="button" data-copiar="{{ $pin }}"
                            class="rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs text-amber-800 hover:bg-amber-100">Copiar</button>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-amber-800">
                Solo guardamos su huella cifrada: ni nosotros podemos leerlo después. Si se pierde, se rota otro.
            </p>
        </div>
    @endif

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
                @if ($codigoKds)
                    {{-- El código se dicta por teléfono en medio del montaje:
                         que se pueda copiar de un clic ahorra el error de leer
                         una letra por otra. No es un secreto — lo que autoriza
                         es el PIN del puesto. --}}
                    <span class="inline-flex items-center gap-1.5">
                        <span class="text-gray-400">Código KDS</span>
                        <code class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs tracking-widest text-gray-700">{{ $codigoKds }}</code>
                        <button type="button" data-copiar="{{ $codigoKds }}" title="Copiar el código"
                            class="rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-50 hover:text-gray-800">Copiar</button>
                    </span>
                @endif
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
    @php
        $bajoMinimo = $stockLevels->filter->isLow()->count();
        $tabs = [
            'resumen' => ['label' => 'Resumen', 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
            'menu' => ['label' => 'Menú', 'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25', 'badge' => $menuCategories->sum(fn ($c) => $c->products->count())],
            'ventas' => ['label' => 'Ventas', 'icon' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z', 'badge' => $conteo30],
            'transacciones' => ['label' => 'Transacciones', 'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z'],
            'inventario' => ['label' => 'Inventario', 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z', 'badge' => $bajoMinimo > 0 ? $bajoMinimo : null, 'tono' => 'alerta'],
            'tiempos' => ['label' => 'Tiempos', 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'badge' => $tiempos->openCount > 0 ? $tiempos->openCount : null, 'tono' => 'alerta'],
            'usuarios' => ['label' => 'Usuarios', 'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z', 'badge' => $vendor->users->count()],
            'config' => ['label' => 'Configuraciones', 'icon' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.03 7.03 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.431l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
        ];
    @endphp

    @include('vendors.tabs.nav', ['tabs' => $tabs])

    {{-- Tab: Resumen --}}
    <div id="tab-resumen" role="tabpanel" aria-labelledby="tab-resumen-item">

        {{-- Los números del comercio, últimos 30 días --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas · 30 días</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format(($bruto30 - $devuelto30) / 100, 2) }}</p>
                @if ($devuelto30 > 0)
                    <p class="mt-0.5 text-xs text-amber-700">− RD$ {{ number_format($devuelto30 / 100, 2) }} devuelto</p>
                @endif
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Transacciones</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">{{ number_format($conteo30) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">Ticket promedio RD$ {{ number_format($ticketPromedio / 100, 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">ITBIS del período</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($itbis30 / 100, 2) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ $modoVigente->getLabel() }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Propinas</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($propinas30 / 100, 2) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">del personal, 10 % legal</p>
            </div>
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-3">
            {{-- Serie diaria --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs lg:col-span-2">
                <h2 class="mb-3 font-medium text-gray-800">Ventas por día <span class="ml-1 text-xs font-normal text-gray-500">últimos 14 días, netas</span></h2>
                <div id="grafica-comercio" class="min-h-48"></div>
            </div>

            {{-- Más vendidos --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="font-medium text-gray-800">Más vendidos <span class="ml-1 text-xs font-normal text-gray-500">30 días</span></h2>
                </header>
                <ul class="divide-y divide-gray-200">
                    @forelse ($topProductos as $fila)
                        <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-gray-800">{{ $fila->nombre }}</p>
                                <p class="text-xs text-gray-500">{{ rtrim(rtrim(number_format((float) $fila->unidades, 3), '0'), '.') }} unidad(es)</p>
                            </div>
                            <span class="shrink-0 font-medium text-gray-800">RD$ {{ number_format($fila->importe / 100, 2) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">Sin ventas en el período.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            {{-- Cómo pagan --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="font-medium text-gray-800">Cómo pagan <span class="ml-1 text-xs font-normal text-gray-500">30 días</span></h2>
                </header>
                <ul class="divide-y divide-gray-200">
                    @forelse ($porMetodo as $fila)
                        {{-- OJO: nada de @php(...) inline en este archivo — se
                             empareja con el primer @endphp ajeno y se traga
                             las directivas que vengan después. --}}
                        <li class="flex items-center justify-between px-5 py-3 text-sm">
                            <span class="text-gray-800">{{ \App\Domains\Sales\Enums\PaymentMethod::tryFrom($fila->method)?->getLabel() ?? $fila->method }}</span>
                            <span class="text-gray-500">{{ $fila->veces }} cobro(s)</span>
                            <span class="font-medium text-gray-800">RD$ {{ number_format($fila->total / 100, 2) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">Sin cobros en el período.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Quién tocó el menú --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="font-medium text-gray-800">Cambios en el menú</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Precios, estado y fiscalidad: quién, cuándo y desde qué valor.</p>
                </header>
                <ul class="divide-y divide-gray-200">
                    @forelse ($actividad as $log)
                        @php
                            $antes = $log->properties['old'] ?? [];
                            $ahora = $log->properties['attributes'] ?? [];
                            $etiquetas = [
                                'price_cents' => 'precio', 'name' => 'nombre', 'active' => 'estado',
                                'itbis_exempt' => 'ITBIS', 'category_id' => 'categoría',
                                'inventory_item_id' => 'insumo vinculado',
                            ];
                            $formatea = function (string $campo, $valor) {
                                if ($valor === null || $valor === '') return '—';
                                return match ($campo) {
                                    'price_cents' => 'RD$ '.number_format(((int) $valor) / 100, 2),
                                    'active' => $valor ? 'en venta' : 'pausado',
                                    'itbis_exempt' => $valor ? 'exento' : 'gravado',
                                    default => (string) $valor,
                                };
                            };
                        @endphp
                        <li class="px-5 py-3 text-sm">
                            <p class="text-gray-800">
                                <span class="font-medium">{{ $log->causer?->name ?? 'Sistema' }}</span>
                                {{ $log->event === 'created' ? 'creó' : ($log->event === 'deleted' ? 'eliminó' : 'actualizó') }}
                                <span class="font-medium">{{ $ahora['name'] ?? $antes['name'] ?? 'un producto' }}</span>
                            </p>
                            @foreach ($ahora as $campo => $valor)
                                @if (isset($etiquetas[$campo]) && $log->event === 'updated')
                                    <p class="text-xs text-gray-500">
                                        {{ $etiquetas[$campo] }}:
                                        <span class="text-gray-400 line-through">{{ $formatea($campo, $antes[$campo] ?? null) }}</span>
                                        →
                                        <span class="font-medium text-gray-700">{{ $formatea($campo, $valor) }}</span>
                                    </p>
                                @endif
                            @endforeach
                            <p class="mt-0.5 text-xs text-gray-400">{{ $log->created_at?->timezone($tz)->format('d/m/Y h:i a') }}</p>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">Sin cambios registrados todavía.</li>
                    @endforelse
                </ul>
            </section>
        </div>

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
                    <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">{{ $event->name }}</p>
                            <p class="text-xs text-gray-500">{{ $event->starts_at->format('d M Y, H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-500">Comisión {{ number_format($event->pivot->commission_bps / 100, 2) }} %</span>
                            <button type="button" data-hs-overlay="#modal-participacion-{{ $event->id }}" aria-haspopup="dialog"
                                class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Ajustar</button>
                        </div>
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
                    <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <p class="text-gray-800">
                                {{ $outlet->name }}
                                @if ($outlet->status->value !== 'active')
                                    <span class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600">{{ $outlet->status->getLabel() }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $outlet->event?->name }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $outlet->kind->getLabel() }}</span>
                            <button type="button" data-hs-overlay="#modal-puesto-{{ $outlet->id }}" aria-haspopup="dialog"
                                class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Editar</button>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Sin puestos: invítalo a un evento y asígnale su barra o cocina.</li>
                @endforelse
            </ul>
        </section>


    </div>

    {{-- Pantallas de cocina (KDS) --}}
    <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
            <div>
                <h2 class="font-medium text-gray-800">Pantallas de cocina</h2>
                <p class="mt-0.5 max-w-2xl text-xs text-gray-500">
                    Una tablet se cuelga una vez: se teclea el código del comercio y el PIN de su puesto, y a partir de ahí entra sola cada mañana.
                    El código no es secreto —se dicta por teléfono—; lo que autoriza es el PIN.
                </p>
            </div>
            <div class="flex items-center gap-3">
                @if ($codigoKds)
                    <code class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 font-mono text-sm tracking-widest text-gray-700">{{ $codigoKds }}</code>
                    <button type="button" data-copiar="{{ $codigoKds }}"
                        class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50 hover:text-gray-800">Copiar</button>
                @endif
                <form method="POST" action="{{ route('event-panel.vendors.kds.code', $vendor) }}"
                    onsubmit="return confirm('El código actual dejará de servir para colgar tabletas nuevas. Las que ya están puestas siguen funcionando. ¿Emitir uno nuevo?')">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50 hover:text-gray-800">
                        {{ $codigoKds ? 'Regenerar código' : 'Emitir código' }}
                    </button>
                </form>
            </div>
        </header>

        {{-- El PIN, puesto por puesto: es de la ventanilla, no del comercio.
             Quien lleva la barra norte no tiene por qué poder colgar una
             pantalla en la cocina sur. --}}
        <ul class="divide-y divide-gray-200">
            @forelse ($outlets as $outlet)
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="text-gray-800">{{ $outlet->name }}</p>
                        @if (filled($outlet->getAttribute('kds_pin_hash')))
                            <p class="text-xs text-gray-500">
                                PIN activo · rotado el {{ $fechaKds($outlet->getAttribute('kds_pin_set_at')) }}
                            </p>
                        @else
                            <p class="text-xs text-gray-400">Sin PIN: este puesto todavía no admite tabletas.</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($bloqueo = $bloqueoKds($outlet))
                            <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-800">
                                Bloqueado hasta las {{ $bloqueo->format('h:i a') }}
                            </span>
                        @endif
                        @if (filled($outlet->getAttribute('kds_pin_hash')))
                            <form method="POST" action="{{ route('event-panel.vendors.kds.pin.unlock', [$vendor, $outlet]) }}">
                                @csrf
                                <button type="submit"
                                    class="rounded-lg border px-2.5 py-1 text-xs {{ $bloqueo ? 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-gray-200 text-gray-700 hover:bg-gray-50' }}">
                                    Desbloquear
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('event-panel.vendors.kds.pin', [$vendor, $outlet]) }}"
                            onsubmit="return confirm('Se emitirá un PIN nuevo para {{ $outlet->name }} y solo se mostrará una vez. Las tabletas ya colgadas seguirán funcionando. ¿Seguimos?')">
                            @csrf
                            <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                {{ filled($outlet->getAttribute('kds_pin_hash')) ? 'Rotar PIN' : 'Generar PIN' }}
                            </button>
                        </form>
                    </div>
                </li>
            @empty
                <li class="px-5 py-6 text-sm text-gray-500">Sin puestos todavía: el PIN es de la ventanilla, así que primero hay que crear una.</li>
            @endforelse
        </ul>

        {{-- Las tabletas colgadas. El nombre es lo único que las distingue a
             la hora de decidir cuál se apaga, y la última vez vista es lo que
             dice si esa que nadie encuentra sigue viva. --}}
        <div class="border-t border-gray-200">
            <div class="flex items-center gap-2 px-5 py-4">
                <h3 class="font-medium text-gray-800">Tabletas enroladas</h3>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tabletasVivas->count() }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-y border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">Tablet</th>
                            <th class="px-5 py-3 font-medium">Vigila</th>
                            <th class="px-5 py-3 font-medium">Última vez vista</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($tabletas as $tableta)
                            <tr class="{{ $tableta->estaRevocada() ? 'bg-gray-50 text-gray-400' : 'hover:bg-gray-50' }}">
                                <td class="px-5 py-3">
                                    <p class="{{ $tableta->estaRevocada() ? '' : 'text-gray-800' }}">{{ $tableta->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $tableta->unit?->name ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">
                                        {{ $tableta->area?->getLabel() ?? 'Barra y cocina' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-gray-500">{{ $horaKds($tableta->last_seen_at) }}</td>
                                <td class="px-5 py-3 text-right">
                                    @if ($tableta->estaRevocada())
                                        <span class="text-xs text-gray-400">Revocada el {{ $fechaKds($tableta->revoked_at) }}</span>
                                    @else
                                        <form method="POST" action="{{ route('event-panel.vendors.kds.devices.revoke', [$vendor, $tableta]) }}"
                                            onsubmit="return confirm('{{ $tableta->name }} dejará de entrar en el acto. Para volver a usarla habrá que enrolarla de nuevo con el código y el PIN. ¿Revocarla?')">
                                            @csrf
                                            <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Revocar</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-gray-500">
                                    Ninguna tablet colgada todavía. En la pantalla se teclea el código de arriba y el PIN de su puesto, y ya no se vuelve a teclear nada.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- El martillo. Rotar cierra las altas futuras y revocar mata las
             sesiones vivas: son cosas distintas y por eso van separadas
             arriba. Pero el caso real —falta una tablet y nadie sabe cuál—
             necesita las dos a la vez, y buscarlas por separado con el
             festival encima es justo cuando se olvida la mitad. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-red-100 bg-red-50 px-5 py-4">
            <p class="max-w-2xl text-xs text-red-800">
                <span class="font-medium">¿Falta una tablet y no sabes cuál era?</span>
                Apaga {{ $tabletasVivas->count() }} tableta(s) y cambia {{ $puestosConPin }} PIN de golpe.
                Después habrá que volver a colgar una por una las que sí están: nadie entrará con lo que ya se sabía.
            </p>
            <form method="POST" action="{{ route('event-panel.vendors.kds.devices.revoke-all', $vendor) }}"
                onsubmit="return confirm('Se apagan TODAS las tabletas de {{ $vendor->name }} y se cambian los PIN de sus puestos. Habrá que enrolar de nuevo, una por una, todas las que sigan en pie. ¿Seguimos?')">
                @csrf
                <button type="submit" class="rounded-lg bg-red-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-red-500">
                    Rotar PIN y revocar TODAS
                </button>
            </form>
        </div>
    </section>
    </div>

    @include('vendors.tabs.menu')

    @include('vendors.tabs.ventas')

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
                            <td class="px-5 py-3 font-mono text-xs">
                                @if ($payment->order !== null)
                                    <a href="{{ route('event-panel.vendors.sales.show', [$vendor, $payment->order_id]) }}" class="text-sky-700 hover:underline">{{ $payment->order->publicNumber() }}</a>
                                @else
                                    —
                                @endif
                            </td>
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

    @include('vendors.tabs.inventario')

    {{-- Tab: Tiempos.
         El MISMO parcial que la pantalla del evento. Allí la pregunta es qué
         puesto del festival va lento; aquí, por qué va lento este comercio.
         Dos copias del informe divergirían, y entonces el mismo puesto
         enseñaría cifras distintas según por dónde se entre. --}}
    <div id="tab-tiempos" class="hidden" role="tabpanel" aria-labelledby="tab-tiempos-item">
        <p class="mb-5 text-sm text-gray-500">
            Cuánto esperó la gente en los puestos de {{ $vendor->name }} y en qué se le fue esa espera,
            en los últimos 30 días. Para comparar este comercio con los demás del festival, mira los
            tiempos desde el evento.
        </p>

        @include('event-panel.events.partials.tiempos', [
            'informe' => $tiempos,
            'comparaComercios' => false,
            // Los tiempos cuentan lo que ya pasó; el botón lleva a lo que está
            // pasando. Filtrado por este comercio, que es de quien se está
            // hablando en esta pantalla.
            'enlaceEnVivo' => route('event-panel.comandas', ['comercio' => $vendor->id]),
        ])
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
                            <th class="px-5 py-3"></th>
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
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button type="button" data-hs-overlay="#modal-rol-{{ $member->id }}" aria-haspopup="dialog"
                                        class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">Cambiar rol</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-gray-500">
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
        <form method="POST" action="{{ route('event-panel.vendors.update', $vendor) }}" enctype="multipart/form-data"
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

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p class="font-medium text-gray-800">Fiscalidad</p>
                    <p class="mb-3 mt-0.5 text-xs text-gray-500">Cómo se relaciona el precio de carta con el ITBIS. Los productos exentos no gravan en ninguna de las dos modalidades.</p>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-gray-500">Modalidad de ITBIS</span>
                        <select name="itbis_mode" class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                            <option value="" @selected(old('itbis_mode', $vendor->itbis_mode?->value) === null || old('itbis_mode', $vendor->itbis_mode?->value) === '')>
                                Como la cuenta ({{ $modoCuenta->getLabel() }})
                            </option>
                            @foreach (\App\Domains\Sales\Enums\ItbisMode::cases() as $modo)
                                <option value="{{ $modo->value }}" @selected(old('itbis_mode', $vendor->itbis_mode?->value) === $modo->value)>{{ $modo->getLabel() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="mt-2 text-xs text-gray-500">
                        <span class="font-medium text-gray-700">Incluido:</span> el precio ya lleva el 18 % y el total no crece.
                        <span class="font-medium text-gray-700">Se suma:</span> el precio es la base y el impuesto se añade al cobrar.
                        Cambiarla NO reescribe precios ni ventas pasadas: revisa tu carta después de cambiarla.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500">Guardar configuración</button>
            </div>
        </form>
    </div>

@include('vendors.tabs.modales')

    @include('vendors.tabs.persistencia')

    <script>
        window.addEventListener('load', function () {
            if (typeof ApexCharts === 'undefined') return;

            new ApexCharts(document.querySelector('#grafica-comercio'), {
                chart: { type: 'area', height: 220, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Ventas netas (RD$)', data: @json($serie->pluck('total')) }],
                xaxis: { categories: @json($serie->pluck('dia')), labels: { style: { colors: '#6b7280', fontSize: '11px' } } },
                yaxis: { labels: { style: { colors: '#6b7280', fontSize: '11px' }, formatter: (v) => 'RD$ ' + v.toLocaleString('es-DO') } },
                colors: ['#0284c7'],
                stroke: { curve: 'smooth', width: 2 },
                fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                dataLabels: { enabled: false },
                grid: { borderColor: '#e5e7eb', strokeDashArray: 3 },
                tooltip: { y: { formatter: (v) => 'RD$ ' + v.toLocaleString('es-DO', { minimumFractionDigits: 2 }) } },
            }).render();
        });
    </script>

    {{-- Copiar el código o un PIN. Delegado en el documento porque los
         botones aparecen y desaparecen con el flash, y engancharlos uno a uno
         al cargar dejaría muerto justo el del PIN recién emitido. --}}
    <script>
        document.addEventListener('click', function (evento) {
            const boton = evento.target.closest('[data-copiar]');
            if (!boton) return;

            const original = boton.textContent;
            navigator.clipboard?.writeText(boton.dataset.copiar).then(function () {
                boton.textContent = 'Copiado';
                setTimeout(() => { boton.textContent = original; }, 1500);
            });
        });
    </script>

    {{-- Modal: editar datos --}}
    <div id="modal-editar" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
        <div class="m-3 mt-14 sm:mx-auto sm:w-full sm:max-w-md">
            <form method="POST" action="{{ route('event-panel.vendors.update', $vendor) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
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
            <form method="POST" action="{{ route('event-panel.vendors.users.store', $vendor) }}"
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
            <form method="POST" action="{{ route('event-panel.vendors.invite', $vendor) }}"
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
            <form method="POST" action="{{ route('event-panel.vendors.outlets.store', $vendor) }}"
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

    {{-- Modal: participación en un evento — renegociar o retirar.
         Lo ya cobrado no cambia: cada orden lleva su comisión congelada. --}}
    @foreach ($participations as $event)
        <div id="modal-participacion-{{ $event->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-md">
                <div class="rounded-xl border border-gray-200 bg-white shadow-xl">
                    <form method="POST" action="{{ route('event-panel.vendors.commission.update', [$vendor, $event]) }}" class="p-5">
                        @csrf
                        <h3 class="mb-1 font-medium text-gray-800">{{ $event->name }}</h3>
                        <p class="mb-4 text-xs text-gray-500">{{ $event->starts_at->format('d M Y') }}</p>
                        <label for="comision-{{ $event->id }}" class="mb-1.5 block text-sm text-gray-700">Comisión (%)</label>
                        <input id="comision-{{ $event->id }}" name="commission" type="number" step="0.01" min="0" max="100" required
                            value="{{ number_format($event->pivot->commission_bps / 100, 2, '.', '') }}"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        <p class="mt-1.5 text-xs text-gray-500">Se aplica a las ventas de aquí en adelante. Las ya cobradas conservan la suya.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-participacion-{{ $event->id }}">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('event-panel.vendors.events.remove', [$vendor, $event]) }}"
                        onsubmit="return confirm('¿Retirar a {{ $vendor->name }} de {{ $event->name }}? Sus puestos de este evento se cerrarán.')"
                        class="border-t border-gray-200 px-5 py-3">
                        @csrf
                        <button type="submit" class="text-sm text-red-600 hover:text-red-700">Retirar del evento</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Modal: editar un puesto. Su evento y su comercio no se tocan:
         moverlo reescribiría de quién son las ventas que salieron por él. --}}
    @foreach ($outlets as $outlet)
        <div id="modal-puesto-{{ $outlet->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="m-3 mt-16 sm:mx-auto sm:w-full sm:max-w-md">
                <form method="POST" action="{{ route('event-panel.vendors.outlets.update', [$vendor, $outlet]) }}"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                    @csrf
                    <h3 class="mb-1 font-medium text-gray-800">{{ $outlet->name }}</h3>
                    <p class="mb-4 text-xs text-gray-500">{{ $outlet->event?->name }}</p>
                    <div class="space-y-3">
                        <input name="name" value="{{ $outlet->name }}" required maxlength="255"
                            class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        <select name="kind" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            <option value="bar" @selected($outlet->kind->value === 'bar')>Barra</option>
                            <option value="kitchen" @selected($outlet->kind->value === 'kitchen')>Cocina</option>
                            <option value="mixed" @selected($outlet->kind->value === 'mixed')>Mixta</option>
                        </select>
                        <select name="status" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                            <option value="active" @selected($outlet->status->value === 'active')>Activo</option>
                            <option value="closed" @selected($outlet->status->value !== 'active')>Cerrado</option>
                        </select>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-puesto-{{ $outlet->id }}">Cancelar</button>
                        <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    {{-- Modal: cambiar el rol de alguien del comercio --}}
    @foreach ($vendor->users as $member)
        <div id="modal-rol-{{ $member->id }}" class="hs-overlay hidden size-full fixed top-0 start-0 z-80 overflow-y-auto" role="dialog" tabindex="-1">
            <div class="m-3 mt-20 sm:mx-auto sm:w-full sm:max-w-sm">
                <form method="POST" action="{{ route('event-panel.vendors.users.role', [$vendor, $member]) }}"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xl">
                    @csrf
                    <h3 class="mb-1 font-medium text-gray-800">{{ $member->name }}</h3>
                    <p class="mb-4 text-xs text-gray-500">{{ $member->email }}</p>
                    <label for="rol-{{ $member->id }}" class="mb-1.5 block text-sm text-gray-700">Rol</label>
                    <select id="rol-{{ $member->id }}" name="role" required class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                        @foreach ($vendorRoles as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected($member->roles->first()?->name === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">Si el rol nuevo no opera caja, sus sesiones del POS se cierran.</p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50" data-hs-overlay="#modal-rol-{{ $member->id }}">Cancelar</button>
                        <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection
