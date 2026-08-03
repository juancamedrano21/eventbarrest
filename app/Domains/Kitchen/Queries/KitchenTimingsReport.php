<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cuánto se tarda en cocina, contestado entero: los cuatro tramos, el
 * desglose por sitio, lo que sigue abierto y cuál de los tramos manda.
 *
 * LOS CUATRO TRAMOS NO SE SUMAN Y ESO SE ENSEÑA, no se esconde. Comanda a
 * comanda la cuenta cuadra —la espera del cliente es exactamente el retraso
 * de red más la cola más la preparación—, pero las MEDIANAS de cuatro
 * listas distintas no suman entre sí: cada tramo tiene su propia comanda en
 * el centro, y no es la misma. Restarlas da un número que no significa nada,
 * y por eso `esperaSinExplicar()` lleva su aviso escrito encima.
 *
 * Además cada tramo se sostiene sobre un número distinto de comandas, y no
 * por un fallo: una cerveza que va de pendiente a lista de un toque no tiene
 * `started_at`, así que no aporta ni cola ni preparación aunque sí aporte
 * espera. Por eso `samples` viaja dentro de cada tramo y no en el informe.
 */
final readonly class KitchenTimingsReport
{
    public const ESPERA = 'Espera del cliente';

    public const COLA = 'En cola';

    public const PREPARANDO = 'Preparando';

    public const RETRASO_DE_RED = 'Retraso de sincronización';

    /**
     * @param  Collection<int, TimingBreakdown>  $breakdown
     */
    private function __construct(
        public CarbonInterface $from,
        public CarbonInterface $to,
        public TimingSummary $espera,
        public TimingSummary $cola,
        public TimingSummary $preparando,
        public TimingSummary $syncDelay,
        public Collection $breakdown,
        public int $readyCount,
        public int $openCount,
        public ?int $oldestOpenSeconds,
    ) {}

    /**
     * @param  Collection<int, TimingBreakdown>  $breakdown
     */
    public static function from(
        CarbonInterface $from,
        CarbonInterface $to,
        TimingSummary $espera,
        TimingSummary $cola,
        TimingSummary $preparando,
        TimingSummary $syncDelay,
        Collection $breakdown,
        int $readyCount,
        int $openCount,
        ?int $oldestOpenSeconds,
    ): self {
        return new self(
            from: $from,
            to: $to,
            espera: $espera,
            cola: $cola,
            preparando: $preparando,
            syncDelay: $syncDelay,
            breakdown: $breakdown,
            readyCount: $readyCount,
            openCount: $openCount,
            oldestOpenSeconds: $oldestOpenSeconds,
        );
    }

    /** Un informe sin nada dentro: ni puestos, ni ventas, ni tramo alguno. */
    public static function vacio(CarbonInterface $from, CarbonInterface $to): self
    {
        return self::from(
            from: $from,
            to: $to,
            espera: TimingSummary::of(self::ESPERA, []),
            cola: TimingSummary::of(self::COLA, []),
            preparando: TimingSummary::of(self::PREPARANDO, []),
            syncDelay: TimingSummary::of(self::RETRASO_DE_RED, []),
            breakdown: collect(),
            readyCount: 0,
            openCount: 0,
            oldestOpenSeconds: null,
        );
    }

    /** Ni una comanda terminada ni una abierta en toda la ventana. */
    public function isEmpty(): bool
    {
        return $this->readyCount === 0 && $this->openCount === 0;
    }

    /**
     * Cuál de los tres tramos pesa más sobre la espera del cliente: la
     * respuesta a «¿y esto por qué tarda tanto?».
     *
     * El retraso de red COMPITE con los otros dos, y es a propósito. Si lo
     * que más pesa es la red, la respuesta honesta es «el wifi», no «la
     * cocina» — que es justo el error contra el que avisa el ADR-009. Un
     * informe que solo pudiera señalar a la cocina siempre señalaría a la
     * cocina.
     */
    public function cuelloDeBotella(): ?TimingSummary
    {
        $peor = null;

        foreach ([$this->cola, $this->preparando, $this->syncDelay] as $tramo) {
            if ($tramo->medianSeconds === null || ! $this->representa($tramo)) {
                continue;
            }

            if ($peor === null || $tramo->medianSeconds > (int) $peor->medianSeconds) {
                $peor = $tramo;
            }
        }

        return $peor;
    }

    /**
     * Si un tramo puede opinar sobre lo que esperó el cliente.
     *
     * Los tres tramos se miden sobre poblaciones DISTINTAS y disjuntas: el
     * retraso de red solo existe en las ventas que el POS cobró sin señal, la
     * cola solo en las que alguien empezó, y la preparación solo en las
     * terminadas. Comparar sus medianas a pelo deja que cinco ventas offline
     * de doscientas se lleven el veredicto y la pantalla acuse al wifi de un
     * festival donde el wifi iba bien.
     *
     * Se exige que el tramo cubra al menos la mitad de las comandas que
     * sostienen la espera. Por debajo de eso el tramo existe, se enseña con su
     * cifra y su recuento, pero no manda: no hay cuello de botella que
     * declarar y la pantalla debe decir que no lo hay.
     */
    private function representa(TimingSummary $tramo): bool
    {
        $base = $this->espera->samples;

        return $base > 0 && $tramo->samples * 2 >= $base;
    }

    /** El culpable no está en la ventanilla: está en la cobertura. */
    public function elCuelloEsDeLaRed(): bool
    {
        return $this->cuelloDeBotella()?->label === self::RETRASO_DE_RED;
    }

    /**
     * Cuánto de la espera del cliente se lleva un tramo, en tanto por ciento.
     *
     * Es un reparto ORIENTATIVO: divide dos medianas que salen de comandas
     * distintas, así que los tres porcentajes no tienen por qué sumar cien.
     * Sirve para ordenar los tramos de mayor a menor, que es la pregunta que
     * se hace el dueño, y no para repartir culpas al decimal.
     */
    public function pesoSobreLaEspera(TimingSummary $tramo): ?float
    {
        $espera = $this->espera->medianSeconds;

        if ($espera === null || $espera <= 0 || $tramo->medianSeconds === null) {
            return null;
        }

        // Se recorta a 100. Como las tres medianas salen de comandas
        // distintas, un tramo medido sobre un puñado de casos malos puede
        // superar la espera mediana de todos, y la pantalla acabaría
        // imprimiendo «el 300 % de lo que esperó el cliente», que no
        // significa nada y destruye la credibilidad del informe entero.
        return min(100.0, round($tramo->medianSeconds * 100 / $espera, 1));
    }

    /**
     * Lo que el cliente esperó y no cocinó nadie: la espera menos la cola
     * menos la preparación.
     *
     * Casi todo lo que aparezca aquí es el POS vendiendo sin cobertura y
     * sincronizando después. Se calcula para poder ENSEÑARLO al lado de los
     * tres tramos —la resta que el lector va a hacer de cabeza y le va a
     * salir mal— y no para culpar a nadie: al ser resta de medianas de tres
     * listas distintas, puede salir negativo sin que eso signifique nada.
     */
    public function esperaSinExplicar(): ?int
    {
        if ($this->espera->medianSeconds === null
            || $this->cola->medianSeconds === null
            || $this->preparando->medianSeconds === null) {
            return null;
        }

        return $this->espera->medianSeconds - $this->cola->medianSeconds - $this->preparando->medianSeconds;
    }
}
