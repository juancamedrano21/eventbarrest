<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

/**
 * Un tramo de tiempo, resumido para que alguien lo lea y decida algo.
 *
 * NO HAY MEDIA, y la ausencia es la pieza más importante de esta clase. Una
 * comanda que alguien se olvidó de marcar lista hasta el día siguiente vale
 * catorce horas, y catorce horas metidas en un promedio de cuarenta comandas
 * suben la cifra veinte minutos: el informe diría que la cocina va fatal
 * cuando lo único que pasó fue que nadie tocó una tablet. La mediana ni se
 * entera de ese valor, y el p90 lo deja donde le toca, en la cola.
 *
 * El p90 es lo que hay que mirar de verdad: se lee «una de cada diez esperó
 * más que esto», y es la que describe la noche que el cliente recuerda. Una
 * mediana buena con un p90 horrible es un puesto que va bien hasta que se le
 * junta la fila, y eso no se ve en ningún promedio.
 */
final readonly class TimingSummary
{
    /**
     * Por debajo de esto no se publica ninguna cifra.
     *
     * Una mediana de tres comandas no es una medición, es una anécdota — y
     * en cuanto sale impresa alguien la usa para discutir con un comercio.
     * Cinco tampoco es mucho, pero ya es el mínimo por debajo del cual un
     * solo plato manda sobre el resultado.
     */
    public const MINIMO_DE_COMANDAS = 5;

    private function __construct(
        public string $label,
        public int $samples,
        public ?int $medianSeconds,
        public ?int $p90Seconds,
        public ?int $worstSeconds,
    ) {}

    /**
     * Resume una lista de segundos ya medidos.
     *
     * LOS PERCENTILES SE CALCULAN AQUÍ, EN PHP, Y NO EN SQL. MySQL y SQLite
     * no comparten función de percentil —no hay un PERCENTILE_CONT portable
     * entre las dos—, y este proyecto corre SQLite en las pruebas y MySQL en
     * producción: la misma consulta saldría con cifras distintas en cada
     * sitio, o directamente no compilaría en una de las dos. El volumen no
     * lo justifica tampoco: esto mide UN evento, no un histórico de años, y
     * unos miles de enteros caben de sobra en memoria.
     *
     * Método de rango más cercano, no interpolación: así toda cifra que
     * publica el informe es el tiempo REAL de una comanda que existió, y no
     * un promedio entre dos. Cuando alguien pregunte «¿qué comanda tardó
     * eso?», hay una que señalar.
     *
     * @param  list<int>  $segundos
     */
    public static function of(string $label, array $segundos): self
    {
        $total = count($segundos);

        if ($total === 0) {
            return new self($label, 0, null, null, null);
        }

        sort($segundos);

        // El peor caso se publica siempre, aunque haya pocas comandas: no es
        // una estimación que pueda salir sesgada, es un hecho que ocurrió.
        // La mediana y el p90 sí son estimaciones, y con cuatro datos
        // estiman cualquier cosa; por eso se callan.
        $peor = $segundos[$total - 1];

        if ($total < self::MINIMO_DE_COMANDAS) {
            return new self($label, $total, null, null, $peor);
        }

        return new self(
            label: $label,
            samples: $total,
            medianSeconds: self::percentil($segundos, 0.50),
            p90Seconds: self::percentil($segundos, 0.90),
            worstSeconds: $peor,
        );
    }

    /** Si hay cifras que enseñar o hay que escribir «pocos datos». */
    public function enoughData(): bool
    {
        return $this->samples >= self::MINIMO_DE_COMANDAS;
    }

    /**
     * Ni una sola comanda sostiene este tramo.
     *
     * No es lo mismo que «pocos datos»: una barra que sirve la cerveza sin
     * pasar por «en proceso» no tiene NINGÚN tiempo de preparación, y eso no
     * es un fallo de medición, es cómo se sirve una cerveza.
     */
    public function isEmpty(): bool
    {
        return $this->samples === 0;
    }

    /**
     * @param  list<int>  $ordenados  ascendente y no vacío
     */
    private static function percentil(array $ordenados, float $fraccion): int
    {
        $indice = (int) ceil($fraccion * count($ordenados)) - 1;

        return $ordenados[max(0, $indice)];
    }
}
