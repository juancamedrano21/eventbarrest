{{--
    Los tiempos de despacho, pintados.

    ES COMPARTIDO: lo incluyen la pantalla del organizador (/event-panel, que
    mira el evento entero) y la del comercio (/event-vendor, que mira sus
    puestos). Por eso no lee NADA del contexto —ni $event, ni el comercio
    activo, ni una sola ruta del panel del organizador—: todo llega por
    parámetros, y quien lo incluya pone alrededor su cabecera y sus enlaces.

    Espera dos variables:

      $informe           App\Domains\Kitchen\Queries\KitchenTimingsReport ya
                         calculado por quien llama, con su ventana dentro.

      $comparaComercios  bool. true en el panel del organizador, que mira
                         varios comercios a la vez y necesita el nombre de
                         cada uno en la tabla; false en el del comercio, donde
                         esa columna repetiría su propio nombre en cada fila.
--}}

@php
    // El mínimo lo fija la consulta, no la pantalla: si allí se sube, aquí se
    // sube solo y las dos frases siguen diciendo lo mismo.
    $minimo = \App\Domains\Kitchen\Queries\TimingSummary::MINIMO_DE_COMANDAS;

    // Los tiempos se leen en voz alta, no en segundos: «7 min 30 s» se
    // entiende y «450» hay que dividirlo de cabeza.
    $duracion = function (?int $s): string {
        if ($s === null) {
            return '—';
        }

        if ($s < 60) {
            return $s.' s';
        }

        $minutos = intdiv($s, 60);

        if ($minutos < 60) {
            $resto = $s % 60;

            return $resto === 0 ? $minutos.' min' : $minutos.' min '.$resto.' s';
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto === 0 ? $horas.' h' : $horas.' h '.$resto.' min';
    };

    // Primero lo que el cliente vivió; después las tres piezas en que se
    // reparte. El retraso de red va el último y con su frase encima, para que
    // nadie lo lea como si fuera un tiempo de cocina.
    $tramos = [
        [$informe->espera, 'Desde que pagó hasta que se lo dieron.', true],
        [$informe->cola, 'Lo que tardó alguien en ponerse con ello.', false],
        [$informe->preparando, 'Lo que se tardó cocinándolo.', false],
        [$informe->syncDelay, 'Lo que tardó la venta en llegar al servidor. Eso no es cocina: es cobertura.', false],
    ];

    $cuello = $informe->cuelloDeBotella();
    $peso = $cuello === null ? null : $informe->pesoSobreLaEspera($cuello);
    $sinExplicar = $informe->esperaSinExplicar();

    $totalComandas = $informe->readyCount + $informe->openCount;
    $porcentajeAbierto = $totalComandas === 0 ? 0.0 : round($informe->openCount * 100 / $totalComandas, 1);

    // Una fila del desglose se lee con los cuatro tramos en el mismo orden que
    // las tarjetas de arriba.
    $tramosDe = fn ($fila): array => [$fila->espera, $fila->cola, $fila->preparando, $fila->syncDelay];

    $conActividad = $informe->breakdown->filter(fn ($fila): bool => $fila->hasActivity());
@endphp

@if ($informe->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white px-5 py-12 text-center">
        <p class="text-sm text-gray-500">No hay ninguna comanda en este rango.</p>
        <p class="mt-1 text-sm text-gray-400">Los tiempos se miden sobre ventas cobradas: sin ventas no hay nada que cronometrar.</p>
    </div>
@else
    {{-- Los cuatro tramos --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tramos as [$tramo, $frase, $principal])
            <div class="rounded-xl border p-5 {{ $principal ? 'border-sky-200 bg-sky-50' : 'border-gray-200 bg-white shadow-2xs' }}">
                <p class="text-xs uppercase tracking-wide {{ $principal ? 'text-sky-700' : 'text-gray-500' }}">{{ $tramo->label }}</p>

                @if ($tramo->isEmpty())
                    <p class="mt-1 text-2xl font-semibold text-gray-300">—</p>
                    <p class="mt-1 text-xs text-gray-500">Ninguna comanda de este rango pasó por aquí.</p>
                @elseif (! $tramo->enoughData())
                    <p class="mt-1 text-2xl font-semibold text-gray-400">Pocos datos</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $tramo->samples }} {{ $tramo->samples === 1 ? 'comanda' : 'comandas' }}. Con menos de {{ $minimo }},
                        una sola manda sobre la cifra y no habría con qué defenderla.
                    </p>
                    <p class="mt-2 text-xs text-gray-500">
                        La peor tardó <span class="font-medium text-gray-700">{{ $duracion($tramo->worstSeconds) }}</span>, y eso sí ocurrió.
                    </p>
                @else
                    <p class="mt-1 text-2xl font-semibold {{ $principal ? 'text-sky-900' : 'text-gray-800' }}">{{ $duracion($tramo->medianSeconds) }}</p>
                    <p class="mt-1 text-xs {{ $principal ? 'text-sky-700' : 'text-gray-500' }}">La mitad de las comandas tardó menos que esto.</p>
                    <p class="mt-2 text-xs text-gray-500">
                        <span class="font-medium text-gray-700">{{ $duracion($tramo->p90Seconds) }}</span> —
                        @if ($tramo->p90Seconds === $tramo->worstSeconds)
                            una de cada diez esperó más de esto, y con {{ $tramo->samples }} comandas esa una es la peor de todas.
                        @else
                            una de cada diez esperó más de esto. La peor, {{ $duracion($tramo->worstSeconds) }}.
                        @endif
                    </p>
                @endif

                <p class="mt-3 text-xs {{ $principal ? 'text-sky-700' : 'text-gray-400' }}">{{ $frase }}</p>
            </div>
        @endforeach
    </div>

    {{-- El veredicto: de los cuatro números de arriba, cuál manda --}}
    @if ($cuello === null)
        <div class="mt-5 rounded-xl border border-gray-200 bg-white px-5 py-4 text-sm text-gray-600">
            Todavía no hay con qué señalar a nadie. Ningún tramo llega a {{ $minimo }} comandas, y un veredicto sacado de menos
            que eso es una anécdota con la que alguien acabaría discutiendo con un puesto.
        </div>
    @elseif ($informe->elCuelloEsDeLaRed())
        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
            <p class="font-medium">Esto no es la cocina: es la cobertura.</p>
            <p class="mt-1">
                Lo que más pesa en la espera del cliente es el retraso de sincronización, con
                <strong class="font-medium">{{ $duracion($cuello->medianSeconds) }}</strong> de mediana{{ $peso === null ? '' : ', alrededor del '.number_format($peso, 0).' % de lo que esperó' }}.
                El POS cobra sin red y el servidor se entera después: durante todo ese rato nadie en la ventanilla sabía
                siquiera que ese pedido existía. Antes de hablarle a nadie de lo lento que va, mira el wifi de la zona.
            </p>
        </div>
    @else
        <div class="mt-5 rounded-xl border border-gray-200 bg-white px-5 py-4 text-sm text-gray-700">
            <p class="font-medium text-gray-800">Dónde se va la espera</p>
            <p class="mt-1">
                De los <strong class="font-medium">{{ $duracion($informe->espera->medianSeconds) }}</strong> que esperó el cliente
                que quedó justo en el medio, el tramo que más pesa es <strong class="font-medium">«{{ $cuello->label }}»</strong>, con
                {{ $duracion($cuello->medianSeconds) }}{{ $peso === null ? '' : ' — cerca del '.number_format($peso, 0).' % de la espera' }}.
            </p>
            @if ($sinExplicar !== null && $sinExplicar > 0)
                <p class="mt-2 text-xs text-gray-500">
                    Sobran unos {{ $duracion($sinExplicar) }} de espera que no cocinó nadie; casi siempre es el POS vendiendo sin
                    cobertura y sincronizando más tarde. Es una orientación y no una resta que vaya a cuadrar: cada tramo tiene su
                    propia comanda en el centro, así que los porcentajes tampoco tienen por qué sumar cien.
                </p>
            @endif
        </div>
    @endif

    {{-- El desglose: barra y cocina nunca en la misma fila --}}
    @if ($conActividad->isNotEmpty())
        <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xs">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">{{ $comparaComercios ? 'Comercio y puesto' : 'Puesto' }}</th>
                            <th class="px-5 py-3 font-medium">Área</th>
                            <th class="px-5 py-3 text-right font-medium">Espera</th>
                            <th class="px-5 py-3 text-right font-medium">En cola</th>
                            <th class="px-5 py-3 text-right font-medium">Preparando</th>
                            <th class="px-5 py-3 text-right font-medium">Red</th>
                            <th class="px-5 py-3 text-right font-medium">Comandas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($conActividad as $fila)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    @if ($comparaComercios)
                                        <p class="font-medium text-gray-800">{{ $fila->vendorName }}</p>
                                        <p class="text-xs text-gray-500">{{ $fila->unitName }}</p>
                                    @else
                                        <p class="font-medium text-gray-800">{{ $fila->unitName }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">{{ $fila->area->getLabel() }}</span>
                                </td>
                                @foreach ($tramosDe($fila) as $tramo)
                                    <td class="px-5 py-3 text-right">
                                        @if ($tramo->enoughData())
                                            <span class="font-medium text-gray-800">{{ $duracion($tramo->medianSeconds) }}</span>
                                            <span class="block text-xs text-gray-500">p90 {{ $duracion($tramo->p90Seconds) }}</span>
                                        @elseif ($tramo->isEmpty())
                                            <span class="text-gray-300">—</span>
                                        @else
                                            <span class="text-xs text-gray-500">pocos datos</span>
                                            <span class="block text-xs text-gray-400">peor {{ $duracion($tramo->worstSeconds) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-5 py-3 text-right">
                                    <span class="text-gray-800">{{ $fila->readyCount }}</span>
                                    <span class="block text-xs {{ $fila->openCount > 0 ? 'text-amber-600' : 'text-gray-400' }}">
                                        @if ($fila->openCount > 0)
                                            {{ $fila->openCount }} sin cerrar · {{ number_format($fila->openPercent(), 0) }} %
                                        @else
                                            todas cerradas
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            Las filas van de más lenta a más rápida por la espera del cliente, que es donde hay que mirar primero.
            La barra y la cocina van separadas a propósito: servir una cerveza y hacer un plato no son el mismo oficio, y una
            fila que los promediara haría parecer más rápido al puesto que más bebida vende sin que su gente hiciera nada mejor.
            «Pocos datos» quiere decir que ese tramo no llega a {{ $minimo }} comandas; la peor sí se enseña, porque ocurrió.
        </p>
    @endif

    {{-- Lo que sigue abierto: el contrapeso de todo lo de arriba --}}
    <div class="mt-6 rounded-xl border px-5 py-4 {{ $porcentajeAbierto >= 20.0 ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }}">
        @if ($informe->openCount === 0)
            <p class="text-sm text-gray-700">
                <span class="font-medium text-gray-800">Nada colgado.</span>
                Las {{ $informe->readyCount }} {{ $informe->readyCount === 1 ? 'comanda' : 'comandas' }} cobradas en este rango salieron,
                así que los tiempos de arriba describen todo lo que pasó y no solo lo que acabó bien.
            </p>
        @else
            <p class="text-sm {{ $porcentajeAbierto >= 20.0 ? 'text-amber-800' : 'text-gray-700' }}">
                <span class="font-medium">{{ $informe->openCount }} {{ $informe->openCount === 1 ? 'comanda sigue' : 'comandas siguen' }} sin cerrar</span>
                de las {{ $totalComandas }} que se cobraron ({{ number_format($porcentajeAbierto, 0) }} %).
                @if ($informe->oldestOpenSeconds !== null)
                    La más vieja lleva sin cerrar desde hace {{ $duracion($informe->oldestOpenSeconds) }}.
                @endif
            </p>
            <p class="mt-2 text-xs {{ $porcentajeAbierto >= 20.0 ? 'text-amber-800' : 'text-gray-500' }}">
                Ninguna de ellas entra en las medianas de arriba, porque no tienen final que medir. Léelo así:
                <strong class="font-medium">un puesto que deja de marcar sus comandas sale con tiempos perfectos</strong> —
                cierra las tres fáciles, deja diez colgadas y encabeza el informe. Cuando este número sea alto, los tiempos de
                ese sitio no dicen que vaya rápido, dicen que no se está marcando.
            </p>
            @if ($informe->oldestOpenSeconds !== null && $informe->oldestOpenSeconds > 7200)
                <p class="mt-2 text-xs {{ $porcentajeAbierto >= 20.0 ? 'text-amber-800' : 'text-gray-500' }}">
                    Un «sin cerrar desde hace» de horas casi nunca es alguien esperando de pie: es una comanda que nadie cerró
                    jamás. Se cuenta desde ahora mismo y no desde el final del rango, que es lo que hay que preguntarse cuando
                    el cliente todavía está delante.
                </p>
            @endif
        @endif
    </div>
@endif
