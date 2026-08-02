{{-- La navegación del comercio, compartida por /panel y /comercio.
     $tabs: id => ['label', 'icon' (path del svg), 'badge', 'tono']. --}}
<nav class="mb-6 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1" role="tablist" aria-orientation="horizontal">
    @foreach ($tabs as $id => $tab)
        <button type="button" id="tab-{{ $id }}-item" data-hs-tab="#tab-{{ $id }}" aria-controls="tab-{{ $id }}" role="tab"
            class="{{ $loop->first ? 'active ' : '' }}group inline-flex shrink-0 items-center gap-2 rounded-lg border border-transparent px-3 py-2 text-sm font-medium text-gray-500
                   transition hover:bg-gray-100 hover:text-gray-800
                   hs-tab-active:border-sky-200 hs-tab-active:bg-sky-50 hs-tab-active:text-sky-700 hs-tab-active:shadow-2xs">
            <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}"/>
            </svg>
            {{ $tab['label'] }}
            @if (($tab['badge'] ?? null) !== null && $tab['badge'] !== '')
                <span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums
                    {{ match ($tab['tono'] ?? 'neutro') {
                        'alerta' => 'bg-red-100 text-red-700',
                        'ok' => 'bg-teal-100 text-teal-700',
                        default => 'bg-gray-100 text-gray-600 group-[.active]:bg-sky-100 group-[.active]:text-sky-700',
                    } }}">
                    {{ $tab['badge'] }}
                </span>
            @endif
        </button>
    @endforeach
</nav>
