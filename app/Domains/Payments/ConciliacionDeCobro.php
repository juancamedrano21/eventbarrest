<?php

declare(strict_types=1);

namespace App\Domains\Payments;

/**
 * Lo que Cybersource sabe de una referencia nuestra, para decidir si se
 * reintenta un cobro incierto.
 *
 * Existe como objeto y no como `list<CobroEncontrado>` a secas por una razón
 * concreta: con una lista pelada, la lectura natural de `[] === $cobros` es
 * «no se cobró, reintenta», y esa lectura es FALSA durante los primeros
 * segundos. Medido contra apitest el 2026-08-07: a 0,3 s del cobro la
 * búsqueda devolvía `totalCount: 0`, y a 4,6 s ya devolvía la transacción.
 * O sea que preguntar inmediatamente después del corte —que es justo cuando
 * uno pregunta— da el resultado que invita al doble cobro.
 *
 * Por eso la única pregunta que autoriza a reintentar tiene nombre propio y
 * exige decir cuántos segundos han pasado: `sePuedeReintentar()`.
 */
final readonly class ConciliacionDeCobro
{
    /**
     * El retraso de indexado medido, con margen. No es un contrato del API:
     * es una observación, así que se usa como suelo, nunca como garantía.
     */
    public const SEGUNDOS_DE_INDEXADO = 15;

    /**
     * @param  list<CobroEncontrado>  $cobros
     */
    public function __construct(
        public string $referencia,
        public int $total,
        public array $cobros,
    ) {}

    /** ¿Cybersource tiene ALGÚN rastro de esta referencia? */
    public function hayRastro(): bool
    {
        return $this->cobros !== [];
    }

    /** El cobro aprobado, si lo hay. Es con el que se concilia y se despacha. */
    public function cobroAprobado(): ?CobroEncontrado
    {
        foreach ($this->cobros as $cobro) {
            if ($cobro->aprobado) {
                return $cobro;
            }
        }

        return null;
    }

    /**
     * La ÚNICA pregunta que autoriza a reenviar el cobro.
     *
     * Dos condiciones, y las dos son necesarias:
     *
     * 1. Que no haya rastro. Si existe una transacción con esta referencia,
     *    reenviar es duplicarla — aunque esté rechazada, porque entonces la
     *    conciliación con PortalDOM ve dos intentos donde hubo uno.
     * 2. Que haya pasado el retraso de indexado. Un `totalCount: 0` a los dos
     *    segundos del corte no significa «no se cobró», significa «todavía no
     *    aparece». Sin esta condición la conciliación no protege de nada: da
     *    luz verde exactamente en el momento en que menos sabe.
     *
     * @param  int  $segundosDesdeElCobro  desde que se lanzó la llamada que se cortó
     */
    public function sePuedeReintentar(int $segundosDesdeElCobro): bool
    {
        return ! $this->hayRastro() && $segundosDesdeElCobro >= self::SEGUNDOS_DE_INDEXADO;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraLog(): array
    {
        return [
            'referencia' => $this->referencia,
            'total' => $this->total,
            'cobros' => array_map(static fn (CobroEncontrado $c): array => $c->paraLog(), $this->cobros),
        ];
    }
}
