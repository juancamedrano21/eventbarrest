@extends($panelLayout)

@section('title', 'Dashboard')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">Dashboard</h1>
        <p class="mt-1 text-sm text-gray-500">Los números vivos de {{ auth()->user()?->tenant?->name }}.</p>
    </div>

    {{-- KPIs --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Ventas de hoy</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesToday / 100, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Últimos 30 días</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($sales30 / 100, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">Cajas abiertas</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">{{ $openSessions }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ $esOrganizador ? 'Comercios' : 'Cuenta' }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800">{{ $vendorsCount ?? '—' }}</p>
        </div>
    </div>

    {{-- Serie diaria --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
        <h2 class="mb-3 font-medium text-gray-800">Ventas por día <span class="ml-1 text-xs font-normal text-gray-500">últimos 14 días</span></h2>
        <div id="grafica-ventas" class="min-h-48"></div>
    </div>

    @if ($esOrganizador)
        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Por comercio --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="font-medium text-gray-800">Ventas por comercio</h2>
                </header>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($porComercio as $fila)
                            <tr>
                                <td class="px-5 py-3 text-gray-800">{{ $fila->nombre }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $fila->ordenes }} orden(es)</td>
                                <td class="px-5 py-3 text-right font-medium text-gray-800">RD$ {{ number_format($fila->total / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-5 py-8 text-center text-gray-500">Sin ventas todavía: llegarán desde el POS.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            {{-- Comisión por evento: el reporte del organizador --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="font-medium text-gray-800">Tu comisión por evento</h2>
                </header>
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr><th class="px-5 py-2.5 font-medium">Evento</th><th class="px-5 py-2.5 text-right font-medium">Vendido</th><th class="px-5 py-2.5 text-right font-medium">Tu comisión</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($porEvento as $fila)
                            <tr>
                                <td class="px-5 py-3 text-gray-800">{{ $fila->nombre }}</td>
                                <td class="px-5 py-3 text-right text-gray-600">RD$ {{ number_format($fila->bruto / 100, 2) }}</td>
                                <td class="px-5 py-3 text-right font-medium text-teal-700">RD$ {{ number_format(round((float) $fila->comision) / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-8 text-center text-gray-500">Sin ventas en eventos todavía.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="border-t border-gray-200 px-5 py-3 text-xs text-gray-500">Calculada sobre lo cobrado, con la comisión pactada en cada participación.</p>
            </section>
        </div>
    @endif

    <script>
        window.addEventListener('load', function () {
            if (typeof ApexCharts === 'undefined') return;

            new ApexCharts(document.querySelector('#grafica-ventas'), {
                chart: { type: 'area', height: 220, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Ventas (RD$)', data: @json($serie->pluck('total')) }],
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
@endsection
