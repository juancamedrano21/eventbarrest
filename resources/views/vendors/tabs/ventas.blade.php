{{-- Parcial compartido entre /panel (organizador) y /comercio (encargado):
     las acciones llegan en $urls, cada puerta pone las suyas. --}}
    {{-- Tab: Ventas --}}
    <div id="tab-ventas" class="hidden" role="tabpanel" aria-labelledby="tab-ventas-item">
        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas de hoy</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesToday / 100, 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-2xs">
                <p class="text-xs uppercase tracking-wide text-gray-500">Ventas históricas</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">RD$ {{ number_format($salesTotal / 100, 2) }}</p>
            </div>
        </div>
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr><th class="px-5 py-3">Orden</th><th class="px-5 py-3">Puesto</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Total</th><th class="px-5 py-3">Cobrada</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($recentOrders as $order)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3"><a href="{{ $urls['venta']($order) }}" class="font-mono text-sm font-medium text-sky-700 hover:underline">{{ $order->publicNumber() }}</a></td>
                            <td class="px-5 py-3 text-gray-600">{{ $order->operatingUnit?->name }}</td>
                            <td class="px-5 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs {{ $order->status->value === 'paid' ? 'bg-teal-100 text-teal-800' : ($order->status->value === 'void' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600') }}">{{ $order->status->getLabel() }}</span></td>
                            <td class="px-5 py-3 text-right text-gray-800">RD$ {{ number_format($order->total_cents / 100, 2) }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $order->paid_at?->timezone(config('app.business_timezone'))->format('d M, h:i a') ?? '—' }}</td>
                            <td class="px-5 py-3 text-right"><a href="{{ $urls['venta']($order) }}" class="text-xs font-medium text-sky-700 hover:underline">Ver detalle</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">Sin ventas todavía: llegarán desde el POS de sus cajeros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

