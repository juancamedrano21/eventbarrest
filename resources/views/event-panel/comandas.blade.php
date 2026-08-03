@extends($panelLayout)

@section('title', 'Comandas en vivo')

@section('content')
    {{--
        El tablero del organizador: las comandas de TODOS sus comercios a la
        vez, agrupadas por comercio y dentro por puesto.

        La pinta ENTERA el JavaScript de abajo, incluido el primer vistazo, y
        es a propósito. Si el primer pintado lo hiciera Blade y los siguientes
        el navegador, habría dos renderizadores del mismo tablero: el primer
        arreglo que alguien hiciera en uno de los dos dejaría la pantalla
        recién abierta diciendo una cosa y el sondeo de cinco segundos después
        diciendo otra. El servidor manda los datos ya calculados; aquí solo hay
        una forma de dibujarlos.
    --}}
    @php
        // El sondeo pregunta por lo MISMO que se está mirando: si la pantalla
        // está filtrada por un comercio y el feed no, el tablero se repintaría
        // entero con los ocho comercios cinco segundos después de abrirlo.
        $urlDelFeed = route('event-panel.comandas.feed', array_filter([
            'evento' => $evento?->id,
            'comercio' => $comercio?->id,
        ]));

        // La hora del servidor en el momento de renderizar, para que ni el
        // primer vistazo dependa del reloj del portátil que lo mira.
        $horaDelServidor = now()->toIso8601String();
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Comandas en vivo</h1>
            <p class="mt-1 text-sm text-gray-500">
                Lo que ahora mismo está esperando en cada puesto del festival. Los tiempos cuentan la noche cuando ya pasó;
                esto es la cola que hay delante.
            </p>
            @if ($evento === null)
                <p class="mt-2 text-sm text-gray-500">Todavía no hay ningún evento en esta cuenta.</p>
            @else
                <p class="mt-2 text-sm text-gray-600">
                    Mirando <strong class="font-medium text-gray-800">{{ $evento->name }}</strong>
                    @if (! request()->filled('evento'))
                        {{-- Que la pantalla eligió sola tiene que decirse: una
                             pantalla que decide por ti sin avisarte acaba
                             enseñándole el evento equivocado a alguien con prisa. --}}
                        @if ($eventoEnMarcha)
                            <span class="text-gray-500">— elegido solo, por ser el que está en marcha.</span>
                        @else
                            {{-- Sin evento en marcha se cae al último que hubo, y hay que
                                 decirlo: si no, alguien con prisa lee «en marcha» sobre un
                                 festival que terminó hace tres semanas y da por bueno que
                                 no hay nadie esperando. --}}
                            <span class="text-amber-700">— ningún evento en marcha ahora; este es el último que hubo.</span>
                        @endif
                    @endif
                    @if ($comercio !== null)
                        <span class="text-gray-500">· solo <strong class="font-medium text-gray-700">{{ $comercio->name }}</strong>.</span>
                        <a href="{{ route('event-panel.comandas', ['evento' => $evento->id]) }}" class="text-sky-600 hover:text-sky-500">Ver todos los comercios</a>
                    @endif
                </p>
            @endif
        </div>

        <div class="flex flex-col items-end gap-2">
            {{-- Un formulario GET normal y corriente: los dos filtros viven en
                 la URL, así que este tablero se puede dejar abierto en una
                 pantalla de la oficina o mandárselo a alguien por mensaje. --}}
            <form method="GET" action="{{ route('event-panel.comandas') }}" class="flex flex-wrap items-center gap-2">
                <select name="evento" class="rounded-lg border-gray-200 py-1.5 text-sm text-gray-700 focus:border-sky-500 focus:ring-sky-500">
                    @foreach ($eventos as $uno)
                        <option value="{{ $uno->id }}" @selected($evento !== null && $uno->id === $evento->id)>{{ $uno->name }}</option>
                    @endforeach
                </select>
                <select name="comercio" class="rounded-lg border-gray-200 py-1.5 text-sm text-gray-700 focus:border-sky-500 focus:ring-sky-500">
                    <option value="">Todos los comercios</option>
                    @foreach ($comercios as $uno)
                        <option value="{{ $uno->id }}" @selected($comercio !== null && $uno->id === $comercio->id)>{{ $uno->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sky-500">Ver</button>
            </form>

            @if ($evento !== null)
                <a href="{{ route('event-panel.events.timings', $evento) }}"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Ver los tiempos</a>
            @endif
        </div>
    </div>

    {{-- La regla del sitio, escrita donde se mira y no en un manual. --}}
    <div class="mb-5 rounded-xl border border-gray-200 bg-gray-50 px-5 py-3 text-sm text-gray-600">
        <strong class="font-medium text-gray-800">Esta pantalla es para mirar, no para operar.</strong>
        Aquí no se puede empezar ni marcar lista ninguna comanda, y no falta el botón: marcarla es un acto de quien la está
        cocinando, delante de la plancha. Si se marcara desde la oficina, las horas que guardamos dejarían de decir cuándo se
        hizo el plato para decir cuándo alguien pulsó, y el informe de tiempos entero dejaría de medir la cocina.
    </div>

    {{-- La franja de frescura. Una pantalla congelada que parece viva es peor
         que una caída: quien la mira creería que no hay nadie esperando. --}}
    <div id="frescura" class="mb-5 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm">
        <span id="frescura-texto" class="text-gray-500">Conectando…</span>
        <span id="frescura-nota" class="text-xs text-gray-400">Se actualiza sola cada 5 segundos.</span>
    </div>

    <div id="tira" class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"></div>

    <p class="mb-2 text-xs text-gray-500">
        Un comercio se pinta <span class="rounded bg-rose-50 px-1.5 py-0.5 font-medium text-rose-700">en rojo</span>
        cuando su comanda más vieja sin cerrar pasa de {{ intdiv($umbral, 60) }} minutos desde que el cliente pagó. No es el
        tiempo en que se hace un plato —hay platos de veinte— sino aquel a partir del cual la gente de la fila empieza a
        preguntar, que es cuando todavía se puede ir al puesto y hacer algo.
    </p>

    {{-- La regla de la batería, escrita donde se mira. Sobre todo las dos
         cosas que NO son una alarma, porque un aviso que salta cuando no pasa
         nada se deja de mirar en una noche y ya no avisa el día que importa. --}}
    <p class="mb-4 text-xs text-gray-500">
        Las tabletas se avisan por debajo del {{ $cuerpo['battery']['low'] }} %
        (<span class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-900">ámbar</span>) y por debajo del
        {{ $cuerpo['battery']['critical'] }} % (<span class="rounded bg-rose-50 px-1.5 py-0.5 font-medium text-rose-700">rojo</span>),
        <strong class="font-medium text-gray-600">salvo que estén cargando</strong>: al 6 % con el cable puesto no hay a quién
        mandar, eso ya se está resolviendo solo. Una tablet que nunca ha dicho su batería sale en gris como
        «sin dato» y no cuenta como avisada — un hueco no es una emergencia. Y si lleva más de
        {{ intdiv($cuerpo['battery']['stale_seconds'], 60) }} minutos sin decir nada, el nivel se queda
        <span class="rounded border border-dashed border-gray-300 px-1.5 py-0.5">con el borde punteado</span>: es lo último
        que supimos, no lo que hay ahora, <strong class="font-medium text-gray-600">y por eso deja de pedir un cable</strong>
        y pasa a contar en «tabletas sin noticias». Son dos avisos porque son dos recados distintos: al primero se va con un
        cable en la mano; al segundo se va a ver por qué esa pantalla ya no contesta, que igual es que se la llevaron.
    </p>

    <div id="tablero" class="space-y-4"></div>

    <noscript>
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
            Este tablero se pinta y se refresca con JavaScript. Sin él no hay forma honesta de enseñarlo: una foto fija de las
            comandas de hace un rato es exactamente lo que esta pantalla no debe ser.
        </div>
    </noscript>

    <script>
        (function () {
            'use strict';

            // Cada cuánto se pregunta. Con la pestaña oculta se baja el ritmo:
            // nadie está mirando, y seis peticiones por minuto por cada pestaña
            // olvidada en la oficina se notan en una noche larga.
            const CADA = 5000;
            const CADA_OCULTA = 30000;

            // A partir de aquí se avisa de que la pantalla puede estar mintiendo.
            // Son cinco sondeos perdidos: uno suelto es la red del recinto.
            const SIN_RESPUESTA = 25000;

            const FEED = @json($urlDelFeed);

            // El primer tablero viaja embebido para que abrir la pantalla no
            // enseñe medio segundo de hueco esperando al primer sondeo.
            let datos = @json($cuerpo);
            let etag = null;
            let ultimaRespuesta = Date.now();
            // El reloj del SERVIDOR según este navegador. Un portátil con la
            // hora corrida pintaría esperas absurdas —«lleva 40 minutos» sobre
            // una comanda de hace dos— y con eso alguien saldría corriendo a un
            // puesto donde no pasa nada. Se arranca con la hora del propio
            // renderizado para que ni el primer vistazo salga torcido.
            let desfase = Date.parse(@json($horaDelServidor)) - Date.now();
            let temporizador = null;

            const $ = (id) => document.getElementById(id);

            const esc = (valor) => String(valor === null || valor === undefined ? '' : valor)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

            const ahora = () => Date.now() + desfase;

            /** Segundos transcurridos desde una marca del servidor. */
            const desde = (iso) => {
                if (!iso) return null;
                const t = Date.parse(iso);
                return Number.isNaN(t) ? null : Math.max(0, Math.round((ahora() - t) / 1000));
            };

            /** Los segundos, leídos en voz alta: «7 min», no «450». */
            const enPalabras = (s) => {
                if (s === null) return '—';
                if (s < 60) return s + ' s';
                const min = Math.floor(s / 60);
                if (min < 60) return min + ' min';
                const h = Math.floor(min / 60);
                const resto = min % 60;
                return resto === 0 ? h + ' h' : h + ' h ' + resto + ' min';
            };

            const umbral = () => (datos.threshold_seconds || 720);

            const atascado = (iso) => {
                const s = desde(iso);
                return s !== null && s >= umbral();
            };

            /** Una cifra grande de la tira de arriba. */
            const indicador = (etiqueta, valor, pie, tono) => `
                <div class="rounded-xl border p-5 ${tono}">
                    <p class="text-xs uppercase tracking-wide opacity-70">${esc(etiqueta)}</p>
                    <p class="mt-1 text-3xl font-semibold">${esc(valor)}</p>
                    <p class="mt-1 text-xs opacity-70">${esc(pie)}</p>
                </div>
            `;

            /** Una comanda, compacta: número, área, qué lleva y su reloj. */
            const comanda = (t) => {
                const lineas = (t.lines || []).map((l) => {
                    const cantidad = Number(l.quantity);
                    const nota = l.notes ? `<span class="text-amber-700"> · ${esc(l.notes)}</span>` : '';
                    return `<li>${esc(cantidad % 1 === 0 ? cantidad : cantidad.toFixed(2))} × ${esc(l.product_name)}${nota}</li>`;
                }).join('');

                const enProceso = t.status === 'in_progress';

                return `
                    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-800">${esc(t.number)}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">${esc(t.area_label)}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs ${enProceso ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-800'}">${esc(t.status_label)}</span>
                                ${t.customer_name ? `<span class="text-xs text-gray-500">${esc(t.customer_name)}</span>` : ''}
                                ${t.late ? '<span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title="La venta se cobró antes de que el servidor se enterara: el POS estaba sin cobertura.">llegó tarde</span>' : ''}
                            </div>
                            <ul class="mt-1 text-xs text-gray-500">${lineas}</ul>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-800" data-reloj="${esc(t.waiting_since)}">—</p>
                            <p class="text-xs text-gray-400">esperando</p>
                            ${t.started_at ? `<p class="mt-0.5 text-xs text-sky-600"><span data-reloj="${esc(t.started_at)}">—</span> en la plancha</p>` : ''}
                        </div>
                    </li>
                `;
            };

            /** Las clases que lleva todo chip de tablet, pinte lo que pinte. */
            const CHIP = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs';

            const TONO_CHIP = {
                mudo: 'border-gray-200 bg-white text-gray-400',
                tranquilo: 'border-gray-200 bg-white text-gray-600',
                ambar: 'border-amber-300 bg-amber-50 text-amber-900',
                rojo: 'border-rose-300 bg-rose-50 text-rose-800',
            };

            /**
             * Una tablet y su batería, en un chip.
             *
             * Aquí NO se elige el color, y eso es el arreglo entero: el color
             * dice «hay que llevarle un cable», y eso no depende solo del
             * nivel sino de que sepamos algo de la tablet ahora mismo. Esa
             * segunda mitad es una resta contra el reloj, así que el chip sale
             * de aquí con los datos puestos y lo pinta relojes(), que corre
             * cada segundo. Si se pintara aquí, un 8 % de hace tres horas se
             * quedaría en rojo hasta el siguiente sondeo.
             */
            const pantalla = (t, comercio) => {
                const sinDato = t.percent === null || t.percent === undefined;

                // Cargando se dice con letras y no con un rayito: es la mitad
                // de la información —un 8 % con cable no es un 8 %— y un icono
                // de tres píxeles no se lee desde la puerta de la oficina.
                const cable = t.charging === true
                    ? '<span class="rounded bg-sky-100 px-1.5 text-xs text-sky-800">cargando</span>'
                    : '';

                return `
                    <span class="${CHIP} ${TONO_CHIP.tranquilo}"
                        data-tablet
                        data-de="${esc(comercio)}"
                        data-baja="${t.low === true ? '1' : ''}"
                        data-nivel="${sinDato ? '' : esc(t.percent)}"
                        data-medido="${esc(t.measured_at || '')}" data-visto="${esc(t.seen_at || '')}">
                        <span class="font-medium">${esc(t.unit_name)}</span>
                        <span class="opacity-60">${esc(t.name)}</span>
                        <span class="font-semibold">${sinDato ? 'sin dato' : esc(t.percent) + ' %'}</span>
                        ${cable}
                        <span class="opacity-60" data-edad>—</span>
                    </span>
                `;
            };

            /** La tarjeta de un comercio: sus contadores, su peor espera y sus comandas. */
            const tarjeta = (v) => {
                const puestos = (v.units || []).map((u) => `
                    <div>
                        <p class="border-y border-gray-100 bg-gray-50 px-5 py-1.5 text-xs uppercase tracking-wide text-gray-500">${esc(u.name)}</p>
                        <ul class="divide-y divide-gray-100">${(u.tickets || []).map(comanda).join('')}</ul>
                    </div>
                `).join('');

                const contador = (n, etiqueta, tono) => `
                    <div class="text-center">
                        <p class="text-2xl font-semibold ${tono}">${n}</p>
                        <p class="text-xs text-gray-500">${esc(etiqueta)}</p>
                    </div>
                `;

                const cuerpo = v.open === 0
                    ? `<p class="px-5 py-6 text-sm text-gray-500">Sin nada pendiente ahora mismo. Este comercio está al día.</p>`
                    : puestos;

                // La fila de tabletas se pinta aunque el comercio no tenga ni
                // una comanda abierta, y es el caso que importa: el puesto
                // tranquilo es donde nadie mira, y donde una pantalla apagada
                // no se descubre hasta que llega la primera cola.
                const tabletas = (v.tablets || []).length === 0
                    ? ''
                    : `<div class="flex flex-wrap items-center gap-2 border-t border-gray-100 bg-gray-50 px-5 py-2">
                           <span class="text-xs uppercase tracking-wide text-gray-400">Tabletas</span>
                           ${(v.tablets || []).map((t) => pantalla(t, v.name)).join('')}
                       </div>`;

                return `
                    <section class="overflow-hidden rounded-xl border bg-white shadow-2xs" data-comercio data-desde="${esc(v.oldest_since || '')}">
                        <header class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                            <div class="min-w-0">
                                <h2 class="truncate font-medium text-gray-800">${esc(v.name)}</h2>
                                <p class="mt-0.5 text-sm text-gray-500" data-peor>
                                    ${v.oldest_since
                                        ? `La más vieja sin cerrar, ${esc(v.oldest_number || '')}, lleva <span class="font-medium" data-reloj="${esc(v.oldest_since)}">—</span>`
                                        : 'Nada sin cerrar.'}
                                </p>
                            </div>
                            <div class="flex items-center gap-5">
                                ${contador(v.pending, 'pendiente', 'text-amber-600')}
                                ${contador(v.in_progress, 'en proceso', 'text-sky-600')}
                                ${contador(v.ready, 'lista', 'text-gray-400')}
                            </div>
                        </header>
                        ${tabletas}
                        ${cuerpo}
                    </section>
                `;
            };

            /** Dibuja el tablero entero. Solo cuando los datos cambian. */
            function pintar() {
                const comercios = datos.vendors || [];

                $('tablero').innerHTML = comercios.length === 0
                    ? `<div class="rounded-xl border border-gray-200 bg-white px-5 py-12 text-center">
                           <p class="text-sm text-gray-500">No hay ningún puesto que mirar en este evento.</p>
                           <p class="mt-1 text-sm text-gray-400">En cuanto un comercio tenga puesto y empiece a cobrar, sus comandas aparecerán aquí solas.</p>
                       </div>`
                    : comercios.map(tarjeta).join('');

                relojes();
            }

            /**
             * Lo que depende del reloj y no de los datos: los cronómetros, los
             * colores y las dos cifras de la tira que solo existen «ahora».
             *
             * Va aparte del pintado por lo mismo que el ETag se calcula sin la
             * hora del servidor: si un «lleva 12 min» viajara en la respuesta,
             * el cuerpo cambiaría cada segundo y el 304 no ocurriría jamás.
             */
            function relojes() {
                document.querySelectorAll('[data-reloj]').forEach((nodo) => {
                    nodo.textContent = enPalabras(desde(nodo.getAttribute('data-reloj')));
                });

                let atascados = 0;
                let peor = null;

                document.querySelectorAll('[data-comercio]').forEach((nodo) => {
                    const iso = nodo.getAttribute('data-desde');
                    const s = iso ? desde(iso) : null;

                    if (s !== null && (peor === null || s > peor)) peor = s;

                    const rojo = s !== null && s >= umbral();
                    if (rojo) atascados += 1;

                    nodo.classList.toggle('border-rose-300', rojo);
                    nodo.classList.toggle('ring-1', rojo);
                    nodo.classList.toggle('ring-rose-200', rojo);
                    nodo.classList.toggle('border-gray-200', !rojo);

                    const linea = nodo.querySelector('[data-peor]');
                    if (linea) linea.classList.toggle('text-rose-700', rojo);
                });

                // Las tabletas: su color, su edad y las dos cuentas de la tira
                // que las miran. Va aquí y no en pintar() porque lo que pasa
                // cada segundo no es que cambie el nivel, es que envejece: el
                // mismo 80 % significa una cosa recién medido y otra muy
                // distinta tres horas después.
                const caduca = (datos.battery || {}).stale_seconds || 300;
                const critico = (datos.battery || {}).critical === undefined ? 10 : datos.battery.critical;

                // Dos problemas distintos y dos listas distintas de comercios,
                // porque no se arreglan igual: a uno se va con un cable, al
                // otro se va a ver qué ha pasado con la pantalla.
                let sinBateria = 0;
                let sinNoticias = 0;
                const pidenCable = [];
                const calladas = [];

                document.querySelectorAll('[data-tablet]').forEach((chip) => {
                    const medido = chip.getAttribute('data-medido');
                    const s = medido ? desde(medido) : null;

                    // Lo que decide si el número es de fiar NO es cuándo se
                    // midió, sino cuándo se supo de la tablet. Una batería
                    // quieta al 64 % no se vuelve a medir —guardarla otra vez
                    // sería decir «medido ahora» de la misma lectura—, así que
                    // su marca envejece aunque la tablet conteste cada tres
                    // segundos. Mirar la medida daría por muerta a una tablet
                    // perfectamente viva solo por tener la batería estable.
                    const visto = chip.getAttribute('data-visto');
                    const desdeQueSeSupo = visto ? desde(visto) : null;
                    const viejo = desdeQueSeSupo === null || desdeQueSeSupo >= caduca;

                    const nivel = chip.getAttribute('data-nivel');
                    const sinDato = nivel === '';

                    // LA REGLA DEL CABLE, entera y en un sitio: nivel bajo, sin
                    // cargador (las dos las decidió el servidor, están en
                    // data-baja) Y que sepamos de la tablet ahora mismo. Sin lo
                    // tercero, el 8 % de una pantalla que se apagó a las once
                    // sigue pidiendo un cable a las dos de la mañana y manda a
                    // alguien a un puesto vacío.
                    const baja = chip.getAttribute('data-baja') === '1' && !viejo;
                    const rojo = baja && Number(nivel) <= critico;

                    // Borde punteado = esto es un recuerdo, no una medida. Lo
                    // mismo para la que nunca dijo nada: en las dos el número
                    // que se ve no describe la tablet de ahora mismo.
                    const punteado = viejo || s === null ? ' border-dashed' : '';

                    chip.className = CHIP + ' ' + (
                        viejo || sinDato
                            ? TONO_CHIP.mudo
                            : rojo ? TONO_CHIP.rojo : baja ? TONO_CHIP.ambar : TONO_CHIP.tranquilo
                    ) + punteado;

                    // Se cuenta aquí, con el mismo `viejo` que acaba de pintar
                    // el chip, para que la cifra de la tira y lo que se ve
                    // abajo no puedan decir cosas distintas.
                    const suComercio = chip.getAttribute('data-de') || '';

                    if (baja) {
                        sinBateria += 1;
                        if (!pidenCable.includes(suComercio)) pidenCable.push(suComercio);
                    }

                    if (viejo) {
                        sinNoticias += 1;
                        if (!calladas.includes(suComercio)) calladas.push(suComercio);
                    }

                    const edad = chip.querySelector('[data-edad]');
                    if (!edad) return;

                    edad.textContent = s === null
                        ? 'nunca lo ha dicho'
                        : (viejo
                            ? 'sin noticias hace ' + enPalabras(desdeQueSeSupo ?? s)
                            : 'hace ' + enPalabras(s));
                    edad.classList.toggle('font-medium', viejo);
                    edad.classList.toggle('opacity-60', !viejo);
                });

                const total = (datos.totals || {}).open || 0;
                const tono = (mal) => mal ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-gray-200 bg-white text-gray-800';

                $('tira').innerHTML = indicador(
                    'Sin cerrar ahora',
                    total,
                    total === 0 ? 'No hay nadie esperando comida.' : 'Comandas cobradas que todavía no han salido.',
                    tono(false),
                ) + indicador(
                    'La peor espera',
                    enPalabras(peor),
                    peor === null ? 'Nada abierto que cronometrar.' : 'Desde que esa persona pagó.',
                    tono(peor !== null && peor >= umbral()),
                ) + indicador(
                    'Comercios atascados',
                    atascados,
                    atascados === 0 ? 'Ninguno pasa del umbral.' : 'Pasan del umbral y están arriba del todo.',
                    tono(atascados > 0),
                ) + indicador(
                    'Tabletas sin batería',
                    sinBateria,
                    // El pie dice los NOMBRES y no una frase de relleno: la
                    // cifra sola obligaría a bajar por ocho comercios buscando
                    // cuál es, y el puesto pelado suele ser el tranquilo, que
                    // está justo al final de la lista.
                    sinBateria === 0
                        ? 'Ninguna encendida por debajo del ' + ((datos.battery || {}).low || 20) + ' % sin cable.'
                        : 'Hay que llevar un cable a: ' + pidenCable.join(', '),
                    tono(sinBateria > 0),
                ) + indicador(
                    // El otro problema, con su propio nombre. Antes se sumaba
                    // al de arriba y el resultado era mandar a alguien con un
                    // cable a un puesto donde la pantalla ya no estaba. No
                    // sabemos de ella y no sabemos por qué: puede estar
                    // apagada, sin cobertura o descolgada — y a las tres se va
                    // igual, pero a mirar, no a enchufar.
                    'Tabletas sin noticias',
                    sinNoticias,
                    sinNoticias === 0
                        ? 'Todas han dado señal hace menos de ' + enPalabras(caduca) + '.'
                        : 'Hay que ir a ver qué pasa en: ' + calladas.join(', '),
                    // Ámbar y no rojo: es un aviso distinto del de la batería y
                    // tiene que verse distinto, o el ojo los suma otra vez.
                    sinNoticias > 0 ? 'border-amber-200 bg-amber-50 text-amber-900' : tono(false),
                );

                frescura();
            }

            /** Cuánto hace que el servidor contestó, dicho sin adornos. */
            function frescura() {
                const s = Math.max(0, Math.round((Date.now() - ultimaRespuesta) / 1000));
                const ciego = (Date.now() - ultimaRespuesta) > SIN_RESPUESTA;

                $('frescura').classList.toggle('border-rose-300', ciego);
                $('frescura').classList.toggle('bg-rose-50', ciego);
                $('frescura').classList.toggle('border-gray-200', !ciego);
                $('frescura').classList.toggle('bg-white', !ciego);

                $('frescura-texto').className = ciego ? 'font-medium text-rose-800' : 'text-gray-500';
                $('frescura-texto').textContent = ciego
                    ? 'Sin respuesta desde hace ' + enPalabras(s) + '. Lo que ves puede estar viejo: no decidas nada con esta pantalla hasta que vuelva.'
                    : 'Actualizado hace ' + enPalabras(s) + '.';

                $('frescura-nota').textContent = ciego
                    ? 'Sigue intentándolo sola.'
                    : (document.hidden ? 'En segundo plano: cada 30 segundos.' : 'Se actualiza sola cada 5 segundos.');
            }

            // Una petición en vuelo a la vez. Con setInterval y una función
            // asíncrona, un servidor que tarde más de cinco segundos hace que
            // las peticiones se apilen: cada vuelta lanza otra sobre la
            // anterior, y a partir de ahí el servidor va peor por culpa de la
            // propia pantalla que lo está midiendo.
            let enVuelo = false;

            async function refrescar() {
                if (enVuelo) return;
                enVuelo = true;

                try {
                    const cabeceras = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                    if (etag) cabeceras['If-None-Match'] = etag;

                    const respuesta = await fetch(FEED, { headers: cabeceras, credentials: 'same-origin' });

                    if (respuesta.status === 304) {
                        // Nada cambió: eso también es una respuesta buena, y la
                        // franja de frescura tiene que enterarse.
                        ultimaRespuesta = Date.now();
                        return;
                    }

                    if (!respuesta.ok) return;

                    const nuevo = await respuesta.json();
                    etag = respuesta.headers.get('ETag');
                    ultimaRespuesta = Date.now();

                    if (nuevo.server_time) {
                        desfase = Date.parse(nuevo.server_time) - Date.now();
                    }

                    datos = nuevo;
                    pintar();
                } catch (e) {
                    // Sin red no se grita: la franja de frescura ya lo cuenta,
                    // y un cartel rojo por cada sondeo perdido enseñaría a la
                    // gente a ignorarlos justo cuando importan.
                } finally {
                    enVuelo = false;
                }
            }

            function agendar() {
                if (temporizador) clearInterval(temporizador);
                temporizador = setInterval(refrescar, document.hidden ? CADA_OCULTA : CADA);
            }

            document.addEventListener('visibilitychange', function () {
                agendar();
                // Al volver a la pestaña se pregunta ya: nadie quiere mirar
                // medio minuto de tablero viejo mientras espera al siguiente turno.
                if (!document.hidden) refrescar();
            });

            pintar();
            setInterval(relojes, 1000);
            agendar();
            refrescar();
        })();
    </script>
@endsection
