<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Cuánto se tarda en despachar, medido sobre los sellos que el KDS ya venía
 * guardando desde el primer día.
 *
 * Son CUATRO tramos y no tres, y el cuarto es la razón de ser de la clase:
 *
 *   1. Espera del cliente   (device_sold_at ?? paid_at) → ready_at
 *   2. En cola              paid_at        → started_at
 *   3. Preparando           started_at     → ready_at
 *   4. Retraso de red       device_sold_at → paid_at
 *
 * El cuarto se separa SIEMPRE. El POS es offline-first: cobra sin cobertura
 * y sincroniza cuando puede, así que entre que el cajero cobró y que el
 * servidor se enteró pueden pasar minutos en los que nadie de la cocina
 * sabía siquiera que existía el pedido. Sumarlo dentro del tiempo de cocina
 * produce la frase que el ADR-009 dejó avisada por escrito: «la cocina de
 * este comercio es lenta», cuando el problema era el wifi de la esquina. La
 * cocina responde de lo que pudo ver; el retraso de red es de la red.
 *
 * Por eso la espera del cliente NO es la suma de la cola y la preparación:
 * lleva dentro el retraso, y el informe lo enseña en vez de taparlo.
 *
 * Dos consultas fijas y ninguna dentro de un bucle: una para lo terminado y
 * otra para lo abierto. Los percentiles se calculan en PHP —el motivo, en
 * TimingSummary::of()— sobre los segundos ya traídos.
 *
 * @phpstan-type Sitio array{vendorId: int, vendorName: string, unitId: int, unitName: string, area: DispatchArea}
 * @phpstan-type Terminadas array{sitio: Sitio, espera: list<int>, cola: list<int>, preparando: list<int>, red: list<int>, ready: int}
 * @phpstan-type Abiertas array{sitio: Sitio, open: int, oldest: int}
 */
class KitchenTimings
{
    /**
     * Los tiempos de todos los puestos de un evento: la vista del
     * organizador, que compara comercios entre sí.
     */
    public function forEvent(Event $event, CarbonInterface $desde, CarbonInterface $hasta): KitchenTimingsReport
    {
        /** @var array<int, int> $unidades */
        $unidades = EventOutlet::query()
            ->where('event_id', $event->id)
            ->pluck('id')
            ->all();

        return $this->forUnits($unidades, $desde, $hasta);
    }

    /**
     * Los tiempos de unos puestos concretos: la vista del comercio, que solo
     * mira los suyos. El array (y no una unidad suelta) por lo mismo que en
     * KitchenBoard — la cocina compartida que despacha para tres barras solo
     * cambia quién llena la lista.
     *
     * @param  array<int, int>  $unitIds
     */
    public function forUnits(array $unitIds, CarbonInterface $desde, CarbonInterface $hasta): KitchenTimingsReport
    {
        if ($unitIds === []) {
            return KitchenTimingsReport::vacio($desde, $hasta);
        }

        return $this->armar(
            $this->terminadas($unitIds, $desde, $hasta),
            $this->abiertas($unitIds, $desde, $hasta),
            $desde,
            $hasta,
        );
    }

    /**
     * Lo terminado: las comandas que llegaron a `ready_at` y por tanto se
     * pueden medir enteras, agrupadas por (comercio, puesto, área).
     *
     * Aquí está el sesgo de supervivencia que hay que vigilar. Solo lo que
     * salió por la ventanilla tiene tiempo que contar, así que una comanda
     * que lleva dos horas colgada NO aparece en ninguna mediana — y cuanto
     * peor va un puesto, más comandas se le quedan fuera de la medida. Por
     * eso esta consulta nunca viaja sola: abiertas() es su contrapeso.
     *
     * @param  array<int, int>  $unitIds
     * @return array<string, Terminadas>
     */
    private function terminadas(array $unitIds, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        // La ventana se corta por paid_at y no por ready_at: paid_at es la
        // única marca que existe siempre y la que define de qué jornada es
        // la venta. Cortar por ready_at dejaría fuera del informe del sábado
        // justo las comandas que se sirvieron pasada la medianoche, que son
        // las peores y las que hay que ver.
        $filas = KitchenTicket::query()
            ->join('orders as o', 'o.id', '=', 'kitchen_tickets.order_id')
            ->join('operating_units as u', 'u.id', '=', 'kitchen_tickets.operating_unit_id')
            ->join('vendors as v', 'v.id', '=', 'kitchen_tickets.vendor_id')
            ->whereIn('kitchen_tickets.operating_unit_id', $unitIds)
            ->whereNotNull('kitchen_tickets.ready_at')
            ->whereNotNull('o.paid_at')
            ->whereBetween('o.paid_at', [$desde, $hasta])
            ->select([
                'kitchen_tickets.vendor_id as vendor_id',
                'v.name as vendor_name',
                'kitchen_tickets.operating_unit_id as unit_id',
                'u.name as unit_name',
                'kitchen_tickets.area as area',
                'kitchen_tickets.started_at as started_at',
                'kitchen_tickets.ready_at as ready_at',
                'o.paid_at as paid_at',
                'o.device_sold_at as device_sold_at',
            ])
            ->toBase()
            ->get();

        /** @var array<string, Terminadas> $grupos */
        $grupos = [];

        foreach ($filas as $fila) {
            $paidAt = $this->marca($fila->paid_at);
            $readyAt = $this->marca($fila->ready_at);

            // El where de arriba ya los exige; esto es para el analizador,
            // que lee el tipo nullable de la columna y no la cláusula.
            if ($paidAt === null || $readyAt === null) {
                continue;
            }

            $sitio = $this->sitioDeLaComanda($fila);
            $clave = TimingBreakdown::claveDe($sitio['vendorId'], $sitio['unitId'], $sitio['area']);

            $grupo = $grupos[$clave] ?? [
                'sitio' => $sitio,
                'espera' => [],
                'cola' => [],
                'preparando' => [],
                'red' => [],
                'ready' => 0,
            ];

            $deviceSoldAt = $this->marca($fila->device_sold_at);
            $startedAt = $this->marca($fila->started_at);

            $grupo['ready']++;

            // La hora del cajero manda cuando la hay: es cuándo el cliente
            // soltó el dinero y empezó a esperar de verdad. La del servidor
            // es solo cuándo nos enteramos nosotros.
            $grupo['espera'][] = $this->segundos($deviceSoldAt ?? $paidAt, $readyAt);

            // Sin started_at no hay ni cola ni preparación, y no es un fallo
            // de nadie: una cerveza va de pendiente a lista de un solo toque
            // porque nadie tiene una mano libre para marcar el paso de en
            // medio. Esa comanda aporta espera y se calla los otros dos.
            if ($startedAt !== null) {
                $grupo['cola'][] = $this->segundos($paidAt, $startedAt);
                $grupo['preparando'][] = $this->segundos($startedAt, $readyAt);
            }

            if ($deviceSoldAt !== null) {
                $grupo['red'][] = $this->segundos($deviceSoldAt, $paidAt);
            }

            $grupos[$clave] = $grupo;
        }

        return $grupos;
    }

    /**
     * Lo abierto: las ventas cobradas de las que todavía no ha salido nada.
     *
     * Es el antídoto del sesgo. Sin esto, un puesto que cerró tres comandas
     * fáciles y dejó diez colgadas encabezaría el informe con los mejores
     * tiempos del evento.
     *
     * Se lee de ORDERS con left join a las comandas, igual que el tablero, y
     * no de kitchen_tickets: PENDIENTE ES LA AUSENCIA DE FILA, así que la
     * comanda que peor va —la que nadie ha tocado ni una vez— no tiene fila
     * ninguna que contar. Justo la que no puede faltar aquí.
     *
     * Una venta sin fila cuenta UNA vez, en el área que declara el puesto
     * (barra si es barra; cocina en todo lo demás, como en KitchenBoard). No
     * se le abren las líneas para repartirla entre dos áreas: lo que importa
     * de una comanda que nadie tocó es que existe y lleva esperando, no por
     * cuántas ventanillas iba a salir.
     *
     * @param  array<int, int>  $unitIds
     * @return array<string, Abiertas>
     */
    private function abiertas(array $unitIds, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        // Las ventas de la ventana, CON el área de cada una de sus líneas.
        //
        // No se puede resolver con un left join a kitchen_tickets filtrando
        // por ready_at nulo, aunque lo parezca: pendiente es la AUSENCIA de
        // fila. En una venta con barra y cocina donde la barra ya se sirvió y
        // la cocina no la tocó nadie, el join solo produce la fila de la
        // barra —que está lista y se descarta—, y la venta entera desaparece
        // del recuento. Justo la comanda que peor va, la que nadie miró
        // jamás, es la que se esfumaba del antídoto contra el sesgo de
        // supervivencia. Las áreas hay que DERIVARLAS de las líneas y
        // restarles después las que sí se sirvieron.
        $ventas = Order::query()
            ->join('operating_units as u', 'u.id', '=', 'orders.operating_unit_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'u.vendor_id')
            ->leftJoin('order_lines as l', 'l.order_id', '=', 'orders.id')
            ->whereIn('orders.operating_unit_id', $unitIds)
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$desde, $hasta])
            ->select([
                'orders.id as order_id',
                'u.vendor_id as vendor_id',
                'v.name as vendor_name',
                'u.id as unit_id',
                'u.name as unit_name',
                'u.kind as unit_kind',
                'l.dispatch as area',
                'orders.paid_at as paid_at',
                'orders.device_sold_at as device_sold_at',
            ])
            ->toBase()
            ->get();

        $idsDeVenta = $ventas->pluck('order_id')->unique()->all();

        // Y las que YA se sirvieron, para restarlas.
        $servidas = KitchenTicket::query()
            ->whereIn('order_id', $idsDeVenta)
            ->whereNotNull('ready_at')
            ->toBase()
            ->get(['order_id', 'area'])
            ->map(fn (stdClass $t): string => $t->order_id.':'.$t->area)
            ->flip();

        // Las que alguien llegó a tocar: si tienen fila, hubo cocina de por
        // medio y siguen contando aunque el dinero se devolviera después.
        $tocadas = KitchenTicket::query()
            ->whereIn('order_id', $idsDeVenta)
            ->toBase()
            ->get(['order_id', 'area'])
            ->map(fn (stdClass $t): string => $t->order_id.':'.$t->area)
            ->flip();

        // Y las ventas que se devolvieron ENTERAS, con el mismo criterio que
        // el tablero: una venta deshecha que nadie tocó no es una comanda
        // abierta, y contarla aquí inflaría el número que sirve precisamente
        // para desconfiar de las medianas.
        $devueltasEnteras = Order::query()
            ->whereIn('orders.id', $idsDeVenta)
            ->leftJoinSub(
                Refund::query()
                    ->selectRaw('order_id, sum(amount_cents) as devuelto')
                    ->groupBy('order_id'),
                'r',
                'r.order_id',
                '=',
                'orders.id',
            )
            ->whereRaw('coalesce(r.devuelto, 0) >= orders.total_cents')
            ->where('orders.total_cents', '>', 0)
            ->toBase()
            ->pluck('orders.id')
            ->flip();

        $filas = $ventas
            ->reject(fn (stdClass $f): bool => $servidas->has(
                $f->order_id.':'.$this->sitioDeLaVenta($f)['area']->value,
            ))
            ->reject(fn (stdClass $f): bool => $devueltasEnteras->has($f->order_id)
                && ! $tocadas->has($f->order_id.':'.$this->sitioDeLaVenta($f)['area']->value))
            // Una venta con tres tacos aporta UNA comanda de cocina, no tres.
            ->unique(fn (stdClass $f): string => $f->order_id.':'.$this->sitioDeLaVenta($f)['area']->value)
            ->values();

        // Lo abierto se mide contra el reloj de ahora, porque sigue abierto
        // ahora: la pregunta es «cuánto lleva esperando esa persona», y esa
        // cuenta no para cuando termina la ventana del informe.
        $ahora = Carbon::now();

        /** @var array<string, Abiertas> $grupos */
        $grupos = [];

        foreach ($filas as $fila) {
            $paidAt = $this->marca($fila->paid_at);

            if ($paidAt === null) {
                continue;
            }

            $sitio = $this->sitioDeLaVenta($fila);
            $clave = TimingBreakdown::claveDe($sitio['vendorId'], $sitio['unitId'], $sitio['area']);

            $grupo = $grupos[$clave] ?? ['sitio' => $sitio, 'open' => 0, 'oldest' => 0];

            $grupo['open']++;
            $grupo['oldest'] = max(
                $grupo['oldest'],
                $this->segundos($this->marca($fila->device_sold_at) ?? $paidAt, $ahora),
            );

            $grupos[$clave] = $grupo;
        }

        return $grupos;
    }

    /**
     * Junta lo terminado con lo abierto del mismo sitio y arma el informe.
     *
     * Los totales se recalculan sobre TODOS los segundos juntos y no
     * promediando las medianas de cada puesto: la mediana de unas medianas
     * no es la mediana de nada, y le daría el mismo peso al puesto de doce
     * comandas que al de seiscientas.
     *
     * @param  array<string, Terminadas>  $terminadas
     * @param  array<string, Abiertas>  $abiertas
     */
    private function armar(array $terminadas, array $abiertas, CarbonInterface $desde, CarbonInterface $hasta): KitchenTimingsReport
    {
        /** @var list<int> $espera */
        $espera = [];
        /** @var list<int> $cola */
        $cola = [];
        /** @var list<int> $preparando */
        $preparando = [];
        /** @var list<int> $red */
        $red = [];

        $totalListas = 0;
        $totalAbiertas = 0;
        $masVieja = null;

        /** @var Collection<int, TimingBreakdown> $filas */
        $filas = collect();

        foreach (array_keys($terminadas + $abiertas) as $clave) {
            $listas = $terminadas[$clave] ?? null;
            $colgadas = $abiertas[$clave] ?? null;

            if ($listas === null && $colgadas === null) {
                continue;
            }

            $sitio = $listas !== null ? $listas['sitio'] : $colgadas['sitio'];

            $delSitio = [
                'espera' => $listas['espera'] ?? [],
                'cola' => $listas['cola'] ?? [],
                'preparando' => $listas['preparando'] ?? [],
                'red' => $listas['red'] ?? [],
            ];

            $espera = array_merge($espera, $delSitio['espera']);
            $cola = array_merge($cola, $delSitio['cola']);
            $preparando = array_merge($preparando, $delSitio['preparando']);
            $red = array_merge($red, $delSitio['red']);

            $totalListas += $listas['ready'] ?? 0;
            $totalAbiertas += $colgadas['open'] ?? 0;

            if ($colgadas !== null) {
                $masVieja = max($masVieja ?? 0, $colgadas['oldest']);
            }

            $filas->push(TimingBreakdown::from(
                vendorId: $sitio['vendorId'],
                vendorName: $sitio['vendorName'],
                unitId: $sitio['unitId'],
                unitName: $sitio['unitName'],
                area: $sitio['area'],
                espera: TimingSummary::of(KitchenTimingsReport::ESPERA, $delSitio['espera']),
                cola: TimingSummary::of(KitchenTimingsReport::COLA, $delSitio['cola']),
                preparando: TimingSummary::of(KitchenTimingsReport::PREPARANDO, $delSitio['preparando']),
                syncDelay: TimingSummary::of(KitchenTimingsReport::RETRASO_DE_RED, $delSitio['red']),
                readyCount: $listas['ready'] ?? 0,
                openCount: $colgadas['open'] ?? 0,
                oldestOpenSeconds: $colgadas['oldest'] ?? null,
            ));
        }

        return KitchenTimingsReport::from(
            from: $desde,
            to: $hasta,
            espera: TimingSummary::of(KitchenTimingsReport::ESPERA, $espera),
            cola: TimingSummary::of(KitchenTimingsReport::COLA, $cola),
            preparando: TimingSummary::of(KitchenTimingsReport::PREPARANDO, $preparando),
            syncDelay: TimingSummary::of(KitchenTimingsReport::RETRASO_DE_RED, $red),
            // Lo más lento arriba: es donde hay que mirar. Un sitio con
            // pocos datos no tiene mediana y cae al final, donde estorba
            // menos que fingiendo ser el más rápido del evento.
            breakdown: $filas
                ->sortByDesc(fn (TimingBreakdown $fila): int => $fila->espera->medianSeconds ?? -1)
                ->values(),
            readyCount: $totalListas,
            openCount: $totalAbiertas,
            oldestOpenSeconds: $totalAbiertas === 0 ? null : $masVieja,
        );
    }

    /**
     * Quién y dónde, leído de una comanda ya existente.
     *
     * @return Sitio
     */
    private function sitioDeLaComanda(stdClass $fila): array
    {
        return [
            'vendorId' => (int) $fila->vendor_id,
            'vendorName' => (string) $fila->vendor_name,
            'unitId' => (int) $fila->unit_id,
            'unitName' => (string) $fila->unit_name,
            'area' => DispatchArea::coerce((string) $fila->area),
        ];
    }

    /**
     * Quién y dónde, leído de una venta que puede no tener comanda todavía.
     *
     * @return Sitio
     */
    private function sitioDeLaVenta(stdClass $fila): array
    {
        $area = $this->texto($fila->area);

        return [
            'vendorId' => (int) $fila->vendor_id,
            // Una unidad sin comercio es una sucursal del mundo negocio, que
            // no llega a este informe (kitchen_tickets.vendor_id es NOT NULL).
            // Aun así el nombre del puesto sirve de respaldo antes que un
            // hueco en blanco en una tabla que alguien va a leer.
            'vendorName' => $this->texto($fila->vendor_name) ?? (string) $fila->unit_name,
            'unitId' => (int) $fila->unit_id,
            'unitName' => (string) $fila->unit_name,
            // Mixta se resuelve hacia cocina igual que en el tablero: una
            // espera atribuida a la barra cuando era un plato solo consigue
            // que nadie mire donde hay que mirar.
            'area' => $area !== null
                ? DispatchArea::coerce($area)
                : (OperatingUnitKind::coerce((string) $fila->unit_kind) === OperatingUnitKind::Bar
                    ? DispatchArea::Bar
                    : DispatchArea::Kitchen),
        ];
    }

    /**
     * Los segundos entre dos marcas.
     *
     * Un tramo negativo se lleva a cero. No existe el plato que sale antes
     * de pedirse: lo que existe es el reloj de una tablet barata adelantado
     * unos minutos —PlaceOrder ya descarta los desfases grandes, pero admite
     * hasta cinco minutos de futuro— y un puñado de números negativos
     * arrastrando la mediana hacia abajo diría que el puesto sirve antes de
     * cobrar.
     */
    private function segundos(CarbonInterface $desde, CarbonInterface $hasta): int
    {
        return max(0, (int) round($desde->diffInSeconds($hasta, absolute: false)));
    }

    /**
     * Las marcas llegan del driver como texto: la consulta va por toBase()
     * para no instanciar un modelo por comanda, y ahí no hay casts.
     */
    private function marca(mixed $valor): ?CarbonInterface
    {
        $texto = $this->texto($valor);

        return $texto === null ? null : Carbon::parse($texto);
    }

    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
