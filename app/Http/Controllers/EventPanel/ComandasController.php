<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Kitchen\Queries\KitchenBoard;
use App\Domains\Kitchen\Queries\KitchenLineView;
use App\Domains\Kitchen\Queries\KitchenTicketView;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las comandas de TODOS los comercios del evento, a la vez y en vivo, para
 * quien organiza el festival.
 *
 * ES UNA PANTALLA PARA MIRAR, NO PARA OPERAR, y esa decisión es lo que
 * explica la mitad de este archivo. Aquí no hay ni una ruta que cambie un
 * estado, ni la habrá: marcar una comanda es un acto de quien la está
 * cocinando, delante de la plancha, y es justo eso lo que da valor a los
 * tiempos que medimos. Si el organizador pudiera marcarlas desde su oficina,
 * `started_at` y `ready_at` dejarían de significar «cuándo se hizo» para
 * significar «cuándo alguien pulsó», y el informe de tiempos entero —el que
 * se usa para hablar con un comercio sobre lo lento que va su puesto— se
 * volvería mentira. La pantalla mira; la tablet opera.
 *
 * LA CONSULTA ES LA MISMA QUE ALIMENTA LA TABLET: KitchenBoard::forUnits(),
 * con la lista de puestos del evento en vez de la de una tablet. No hay una
 * segunda consulta «para el organizador» y no puede haberla: dos lecturas
 * distintas del mismo tablero divergirían con el primer cambio que alguien
 * hiciera en una sola de ellas, y entonces el organizador vería una comanda
 * que el cocinero no ve —o al revés— justo el día que discutan sobre ella.
 *
 * EL ETag SE CALCULA SIN LA HORA DEL SERVIDOR, igual que en el KDS y por el
 * mismo motivo: la hora viaja en la respuesta para que el navegador pinte los
 * cronómetros contra el reloj del servidor y no contra el suyo, pero si
 * entrara en el hash el ETag cambiaría cada segundo, el 304 no ocurriría
 * jamás y cada pestaña abierta se descargaría el tablero entero cada cinco
 * segundos toda la noche. Se hashea el cuerpo; la hora se añade después.
 *
 * Por lo mismo, aquí no viaja NI UN SEGUNDO TRANSCURRIDO: ni «lleva 12
 * minutos», ni «hay 3 comercios atascados», que también depende del reloj.
 * Viajan MARCAS DE TIEMPO y el umbral en segundos; quién está en rojo y
 * cuánto lleva esperando lo calcula el navegador, que tiene el reloj delante.
 */
class ComandasController extends Controller
{
    use AuthorizesOrganizerPanel;

    /**
     * A partir de cuánta espera un comercio se pinta en rojo.
     *
     * Doce minutos desde que el cliente pagó. No es el tiempo en que se hace
     * un plato —hay platos de veinte— sino aquel a partir del cual la persona
     * de la fila empieza a preguntar, que es cuando el organizador todavía
     * puede ir al puesto y hacer algo. Viaja al navegador en el cuerpo porque
     * es él quien decide el color: aquí no sabemos qué hora es en su pantalla.
     */
    public const SEGUNDOS_DE_ATASCO = 720;

    /**
     * La pantalla. Se pinta ya servida con el primer tablero, para que abrirla
     * no enseñe medio segundo de hueco antes del primer sondeo.
     */
    public function show(Request $request): View
    {
        $this->authorizeOrganizer($request, Permission::ReportsViewTenant);

        [$evento, $comercio, $cuerpo] = $this->tablero($request);

        return view('event-panel.comandas', [
            'evento' => $evento,
            'comercio' => $comercio,
            'cuerpo' => $cuerpo,
            'umbral' => self::SEGUNDOS_DE_ATASCO,
            // Para los dos selectores de la cabecera. Los eventos, del más
            // reciente al más viejo: quien abre esto está mirando el de esta
            // noche, no el del año pasado.
            'eventos' => Event::query()->orderByDesc('starts_at')->get(),
            'comercios' => $evento === null ? collect() : $this->comerciosDe($evento),
        ]);
    }

    /**
     * El JSON que la pantalla sondea. Un SNAPSHOT entero y no un diff: una
     * pestaña que se quedó sin red medio minuto vuelve al estado correcto con
     * la primera respuesta buena, sin saber siquiera que se lo perdió.
     */
    public function feed(Request $request): Response
    {
        $this->authorizeOrganizer($request, Permission::ReportsViewTenant);

        [, , $cuerpo] = $this->tablero($request);

        // Débil (W/) porque lo que se compara es el SIGNIFICADO del tablero y
        // no el byte: dos respuestas con las mismas comandas en el mismo
        // estado son la misma pantalla aunque server_time las separe.
        $etag = 'W/"'.sha1((string) json_encode($cuerpo)).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)->header('ETag', $etag);
        }

        return response()
            ->json($cuerpo + ['server_time' => now()->toIso8601String()])
            ->header('ETag', $etag);
    }

    /**
     * El tablero entero: el evento que se mira, el comercio por el que se
     * filtra (si hay) y el cuerpo que se pinta o se serializa.
     *
     * Lo comparten la pantalla y el feed a propósito. Si cada uno armara lo
     * suyo, el primer arreglo que alguien hiciera en uno de los dos dejaría la
     * pantalla recién abierta diciendo una cosa y el primer sondeo, cinco
     * segundos después, diciendo otra.
     *
     * @return array{0: Event|null, 1: Vendor|null, 2: array<string, mixed>}
     */
    private function tablero(Request $request): array
    {
        $evento = $this->eventoMirado($request);

        if ($evento === null) {
            return [null, null, $this->cuerpoVacio()];
        }

        $comercio = $this->comercioFiltrado($request);

        // Los puestos del evento, ya con su comercio: es la lista que se le
        // pasa al tablero y también la que agrupa las tarjetas después.
        $consulta = EventOutlet::query()
            ->where('event_id', $evento->id)
            ->with('vendor')
            ->orderBy('name');

        if ($comercio !== null) {
            $consulta->where('vendor_id', $comercio->id);
        }

        $puestos = $consulta->get();

        $tarjetas = app(KitchenBoard::class)->forUnits($puestos->modelKeys());

        return [$evento, $comercio, $this->cuerpo($evento, $comercio, $puestos, $tarjetas)];
    }

    /**
     * Qué evento se mira.
     *
     * Sin `?evento=` manda el que está EN MARCHA ahora mismo, que es lo que
     * quiere ver quien abre esto un sábado por la noche. Con varios en marcha
     * —dos ferias el mismo fin de semana— o con ninguno, el más reciente: es
     * la única respuesta que no obliga a elegir a quien solo quería mirar. La
     * pantalla dice cuál eligió, porque una pantalla que decide por ti sin
     * decírtelo acaba enseñando el evento equivocado a alguien con prisa.
     */
    private function eventoMirado(Request $request): ?Event
    {
        $pedido = $request->query('evento');

        if (is_string($pedido) && $pedido !== '') {
            // Escopado por la cuenta: TenantScope falla cerrado, así que el
            // evento de otra productora sencillamente no existe aquí.
            return Event::query()->findOrFail((int) $pedido);
        }

        $ahora = now();

        $enMarcha = Event::query()
            ->where('starts_at', '<=', $ahora)
            ->where('ends_at', '>=', $ahora)
            ->orderByDesc('starts_at')
            ->first();

        return $enMarcha ?? Event::query()->orderByDesc('starts_at')->first();
    }

    /** El comercio por el que se filtra, si vino uno. */
    private function comercioFiltrado(Request $request): ?Vendor
    {
        $pedido = $request->query('comercio');

        if (! is_string($pedido) || $pedido === '') {
            return null;
        }

        return Vendor::query()->findOrFail((int) $pedido);
    }

    /**
     * Los comercios con puesto en el evento, para el selector.
     *
     * @return Collection<int, Vendor>
     */
    private function comerciosDe(Event $evento): Collection
    {
        /** @var Collection<int, Vendor> $comercios */
        $comercios = Vendor::query()
            ->whereIn(
                'id',
                EventOutlet::query()->where('event_id', $evento->id)->select('vendor_id'),
            )
            ->orderBy('name')
            ->get();

        return $comercios;
    }

    /**
     * El tablero agrupado por comercio y, dentro, por puesto.
     *
     * NO son tres columnas globales, y esa es la diferencia entre esta
     * pantalla y la de la tablet. Una columna «pendiente» con las comandas de
     * ocho comercios mezcladas no contesta la única pregunta que se hace el
     * organizador —¿QUÉ PUESTO VA ATASCADO?—: para contestarla habría que ir
     * leyendo nombre por nombre. Agrupado por comercio, la respuesta es la
     * primera tarjeta.
     *
     * @param  Collection<int, EventOutlet>  $puestos
     * @param  Collection<int, KitchenTicketView>  $tarjetas
     * @return array<string, mixed>
     */
    private function cuerpo(Event $evento, ?Vendor $comercio, Collection $puestos, Collection $tarjetas): array
    {
        // De qué puesto es cada venta. KitchenTicketView no lo trae —la tablet
        // no lo necesita, ya sabe dónde está— así que se resuelve con UNA
        // consulta por id, nunca una por tarjeta.
        $deQuePuesto = Order::query()
            ->whereIn('id', $tarjetas->map(fn (KitchenTicketView $t): int => $t->orderId)->unique()->all())
            ->pluck('operating_unit_id', 'id');

        $porPuesto = $tarjetas->groupBy(
            fn (KitchenTicketView $tarjeta): int => (int) ($deQuePuesto->get($tarjeta->orderId) ?? 0),
        );

        $comercios = [];

        foreach ($puestos->groupBy(fn (EventOutlet $puesto): int => (int) $puesto->vendor_id) as $vendorId => $suyos) {
            $unidades = [];
            $contadores = ['pending' => 0, 'in_progress' => 0, 'ready' => 0];
            $masVieja = null;
            $numeroMasViejo = null;
            $nombre = '';

            foreach ($suyos as $puesto) {
                // Todos los puestos del grupo son del mismo comercio, así que
                // basta con el primero. Un puesto de evento SIEMPRE tiene
                // comercio —EventOutlet lo exige al crearse— y por eso aquí no
                // hay respaldo: un hueco en blanco sería un fallo de datos que
                // conviene que se vea, no que se disimule.
                $nombre = $nombre !== '' ? $nombre : $puesto->vendor->name;

                /** @var Collection<int, KitchenTicketView> $delPuesto */
                $delPuesto = $porPuesto->get($puesto->id, collect());

                foreach ($delPuesto as $tarjeta) {
                    $contadores[$tarjeta->status->value]++;
                }

                $abiertas = $delPuesto
                    ->filter(fn (KitchenTicketView $t): bool => $t->status->isOpen())
                    // La que lleva más esperando arriba: dentro de un puesto
                    // también manda lo peor primero.
                    ->sortBy(fn (KitchenTicketView $t): string => $t->esperaDesde()->toIso8601String())
                    ->values();

                $primera = $abiertas->first();

                if ($primera === null) {
                    continue;
                }

                $desde = $primera->esperaDesde()->toIso8601String();

                if ($masVieja === null || $desde < $masVieja) {
                    $masVieja = $desde;
                    $numeroMasViejo = $primera->numero;
                }

                $unidades[] = [
                    'id' => $puesto->id,
                    'name' => $puesto->name,
                    'open' => $abiertas->count(),
                    'oldest_since' => $desde,
                    'tickets' => $abiertas->map(fn (KitchenTicketView $t): array => $this->tarjeta($t))->all(),
                ];
            }

            $comercios[] = [
                'id' => (int) $vendorId,
                'name' => $nombre,
                'pending' => $contadores['pending'],
                'in_progress' => $contadores['in_progress'],
                'ready' => $contadores['ready'],
                'open' => $contadores['pending'] + $contadores['in_progress'],
                'oldest_since' => $masVieja,
                'oldest_number' => $numeroMasViejo,
                'units' => $unidades,
            ];
        }

        // Lo peor primero: el comercio con la comanda más vieja arriba del
        // todo. Nadie mira la tercera pantalla de una lista, y el que está
        // atascado es justo el que no puede quedarse abajo. Los que no tienen
        // nada abierto se van al final, ordenados por nombre para que no
        // bailen entre un sondeo y el siguiente.
        usort($comercios, function (array $a, array $b): int {
            if ($a['oldest_since'] === $b['oldest_since']) {
                return strcmp((string) $a['name'], (string) $b['name']);
            }

            if ($a['oldest_since'] === null) {
                return 1;
            }

            if ($b['oldest_since'] === null) {
                return -1;
            }

            return strcmp((string) $a['oldest_since'], (string) $b['oldest_since']);
        });

        return [
            'event' => [
                'id' => $evento->id,
                'name' => $evento->name,
                'status' => $evento->status->value,
            ],
            'vendor' => $comercio === null ? null : ['id' => $comercio->id, 'name' => $comercio->name],
            'threshold_seconds' => self::SEGUNDOS_DE_ATASCO,
            'totals' => [
                'pending' => array_sum(array_column($comercios, 'pending')),
                'in_progress' => array_sum(array_column($comercios, 'in_progress')),
                'ready' => array_sum(array_column($comercios, 'ready')),
                'open' => array_sum(array_column($comercios, 'open')),
            ],
            'vendors' => $comercios,
        ];
    }

    /**
     * Una comanda, compacta. Lo justo para reconocerla desde lejos: número,
     * área, qué lleva y desde cuándo espera.
     *
     * Ni un dato de dinero. El organizador tiene su liquidación para eso, y lo
     * que se pregunta mirando esto es si hay que ir a un puesto, no cuánto se
     * facturó en él.
     *
     * @return array<string, mixed>
     */
    private function tarjeta(KitchenTicketView $tarjeta): array
    {
        return [
            // Se direcciona por (orden, área) y no por el id de la comanda,
            // porque una comanda pendiente todavía no tiene fila.
            'order_id' => $tarjeta->orderId,
            'area' => $tarjeta->area->value,
            'area_label' => $tarjeta->area->getLabel(),
            'status' => $tarjeta->status->value,
            'status_label' => $tarjeta->status->getLabel(),
            'number' => $tarjeta->numero,
            'customer_name' => $tarjeta->customerName,
            'items_count' => $tarjeta->itemsCount,
            // Desde cuándo espera el cliente: manda el reloj del cajero cuando
            // lo hay, que es el de verdad.
            'waiting_since' => $tarjeta->esperaDesde()->toIso8601String(),
            // Desde cuándo la están haciendo. Con las dos marcas, el navegador
            // sabe qué cronómetro está vivo sin que se lo digamos.
            'started_at' => $tarjeta->startedAt?->toIso8601String(),
            // La venta se cobró hace rato y acaba de aparecer: el POS estaba
            // sin cobertura. Se dice, para que el retraso de la red no se le
            // cuelgue al puesto.
            'late' => $tarjeta->llegoTarde(),
            'lines' => $tarjeta->lines->map(fn (KitchenLineView $linea): array => [
                'quantity' => $linea->cantidad,
                'product_name' => $linea->productName,
                'notes' => $linea->notes,
            ])->all(),
        ];
    }

    /**
     * El tablero de una cuenta que todavía no tiene ni un evento. El feed
     * responde igual que siempre —con su ETag y su 304— en vez de reventar:
     * la pantalla se abre, dice que no hay nada y sigue sondeando por si
     * alguien crea el evento en otra pestaña.
     *
     * @return array<string, mixed>
     */
    private function cuerpoVacio(): array
    {
        return [
            'event' => null,
            'vendor' => null,
            'threshold_seconds' => self::SEGUNDOS_DE_ATASCO,
            'totals' => ['pending' => 0, 'in_progress' => 0, 'ready' => 0, 'open' => 0],
            'vendors' => [],
        ];
    }
}
