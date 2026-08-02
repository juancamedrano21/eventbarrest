@extends($panelLayout)

@section('title', 'Ajustes')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">Ajustes de la cuenta</h1>
        <p class="mt-1 text-sm text-gray-500">Tus datos, cómo se cobra el impuesto y sobre qué cobras tu comisión.</p>
    </div>

    <form method="POST" action="{{ route('event-panel.settings.update') }}" class="max-w-2xl space-y-6">
        @csrf

        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-sm font-medium text-gray-800">Identidad</h2>
            </header>
            <div class="space-y-4 p-5">
                <div>
                    <label for="aj-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre de la cuenta</label>
                    <input id="aj-nombre" name="name" value="{{ old('name', $cuenta->name) }}" required maxlength="255"
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                </div>
                <div>
                    <label for="aj-rnc" class="mb-1.5 block text-sm text-gray-700">RNC <span class="text-gray-400">(opcional)</span></label>
                    <input id="aj-rnc" name="rnc" value="{{ old('rnc', $cuenta->rnc) }}" inputmode="numeric"
                        class="w-full rounded-lg border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-sky-500 focus:ring-sky-500">
                    <p class="mt-1.5 text-xs text-gray-500">Nueve u once dígitos.</p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-sm font-medium text-gray-800">Tu comisión</h2>
            </header>
            <div class="space-y-3 p-5">
                <p class="text-sm text-gray-600">
                    Sobre qué dinero se calcula el porcentaje que pactas con cada comercio.
                    <strong class="font-medium text-gray-800">No es un detalle contable:</strong>
                    sobre una venta de RD$1,000 con ITBIS incluido y propina, un 10 % son
                    RD$84.75 o RD$108.48 según lo que elijas.
                </p>

                @foreach ($bases as $base)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50 has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50">
                        <input type="radio" name="commission_base" value="{{ $base->value }}" class="mt-0.5"
                            @checked(old('commission_base', $cuenta->commission_base?->value) === $base->value)>
                        <span>
                            <span class="block text-sm font-medium text-gray-800">{{ $base->getLabel() }}</span>
                            <span class="mt-0.5 block text-sm text-gray-500">{{ $base->description() }}</span>
                        </span>
                    </label>
                @endforeach

                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Cambiarlo rige para las ventas de aquí en adelante. Cada venta congela la regla
                    con la que se cobró, así que las liquidaciones ya hechas no se mueven.
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white shadow-2xs">
            <header class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-sm font-medium text-gray-800">ITBIS</h2>
            </header>
            <div class="space-y-3 p-5">
                <p class="text-sm text-gray-600">
                    La regla por defecto de la cuenta. Cada comercio puede tener la suya propia
                    desde su perfil.
                </p>
                @foreach ($modos as $modo)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50 has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50">
                        <input type="radio" name="itbis_mode" value="{{ $modo->value }}" class="mt-0.5"
                            @checked(old('itbis_mode', $cuenta->itbis_mode?->value) === $modo->value)>
                        <span>
                            <span class="block text-sm font-medium text-gray-800">{{ $modo->getLabel() }}</span>
                            <span class="mt-0.5 block text-sm text-gray-500">{{ $modo->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500">Guardar</button>
        </div>
    </form>
@endsection
