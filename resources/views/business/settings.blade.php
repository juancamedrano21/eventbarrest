@extends('business.layout')

@section('title', 'Ajustes')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Ajustes de la cuenta</h1>
        <p class="mt-1 text-sm text-gray-500">Los datos del negocio y cómo se cobra el impuesto.</p>
    </div>

    <form method="POST" action="{{ route('business.settings.update') }}" class="max-w-2xl space-y-6">
        @csrf

        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-sm font-medium text-gray-800">Identidad</h2>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label for="ajuste-nombre" class="mb-1.5 block text-sm text-gray-700">Nombre del negocio</label>
                    <input id="ajuste-nombre" name="name" value="{{ old('name', $negocio->name) }}" required maxlength="255"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                </div>
                <div>
                    <label for="ajuste-rnc" class="mb-1.5 block text-sm text-gray-700">RNC <span class="text-gray-400">(opcional)</span></label>
                    <input id="ajuste-rnc" name="rnc" value="{{ old('rnc', $negocio->rnc) }}" inputmode="numeric"
                        class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    <p class="mt-1.5 text-xs text-gray-500">Nueve u once dígitos. Irá en los comprobantes fiscales.</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-sm font-medium text-gray-800">ITBIS</h2>
            </div>
            <div class="space-y-3 p-5">
                <p class="text-sm text-gray-600">
                    Cómo se comporta el 18 % sobre los precios que escribes en el menú. Los productos marcados como exentos no lo llevan en ninguno de los dos casos.
                </p>

                @foreach ($modos as $modo)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50 has-[:checked]:border-gray-900 has-[:checked]:bg-gray-50">
                        <input type="radio" name="itbis_mode" value="{{ $modo->value }}" class="mt-0.5"
                            @checked(old('itbis_mode', $negocio->itbis_mode?->value) === $modo->value)>
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ $modo->getLabel() }}</span>
                            <span class="mt-0.5 block text-sm text-gray-500">{{ $modo->description() }}</span>
                        </span>
                    </label>
                @endforeach

                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Cambiarlo afecta a lo que se cobre de ahora en adelante. Las ventas ya cobradas guardan su propia modalidad congelada: su comprobante ya salió impreso.
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">Guardar</button>
        </div>
    </form>
@endsection
