<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\Services\CybersourceClient;

/**
 * Lo que comparten las acciones que hablan con la bóveda de tokens (TMS):
 * cuándo «no está» significa éxito, y cómo se nombra un token en un log.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * LA REGLA DE «YA NO ESTÁ» ESTÁ AQUÍ Y NO COPIADA EN CADA ACCIÓN.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Es la distinción que decide si se borra la fila local, así que tiene que
 * ser LA MISMA en las tres acciones. Y no es la que se supondría leyendo la
 * documentación: son DOS códigos, no uno, medidos contra apitest el
 * 2026-08-07.
 *
 *   - **404** `{"type":"notFound","message":"Token not found"}` — el id nunca
 *     existió (probado con un id inventado, y con un customer inventado).
 *   - **410** `{"type":"notAvailable","message":"Token not available"}` — el
 *     token existió y ya se borró. Es lo que devuelve un `GET` de un
 *     instrumento después de borrarlo, y también después de borrar su
 *     customer.
 *
 * Los dos significan lo mismo para nosotros —esa credencial ya no puede
 * cobrar— y por eso los dos cuentan como éxito. Tratar el 410 como error
 * dejaría el borrado atascado para siempre en una tarjeta que ya no existe:
 * la fila local no se borraría nunca y el asistente vería una tarjeta
 * fantasma que no consigue quitar.
 *
 * Cualquier OTRO código revienta. «No pude mirar» y «no está» llevan a
 * decisiones opuestas, igual que en `BuscarCobroPorReferencia`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * LO QUE ESTA REGLA AFIRMA ES DE LA PAREJA, NO DEL INSTRUMENTO.
 * ─────────────────────────────────────────────────────────────────────────
 * La ruta del TMS lleva las DOS piezas, así que un 404 puede significar
 * también «ese customer no es el de esta tarjeta». Leerlo como «la tarjeta ya
 * no existe» borraría la fila dejando el token VIVO y sin nada que lo nombre
 * — justo el estado que este slice entero está escrito para evitar.
 *
 * NO se cierra preguntando después, porque no se puede: comprobar el
 * instrumento exige el customer bueno, que es precisamente lo que estaría
 * mal. Se cierra por el otro lado, en el ORIGEN: la pareja se escribe una sola
 * vez, de la misma respuesta del mismo cobro, y `EventAppCard` prohíbe
 * reescribirla después (`EventAppException::credencialDeTarjetaInmutable`).
 * Sin escritura que las desempareje, un 404 sobre una pareja que salió junta
 * solo puede significar lo que decimos que significa.
 */
abstract class AccionSobreLaBoveda
{
    /** @var list<int> */
    private const YA_NO_ESTA = [404, 410];

    public function __construct(protected readonly CybersourceClient $cliente) {}

    protected static function yaNoEsta(int $httpStatus): bool
    {
        return in_array($httpStatus, self::YA_NO_ESTA, true);
    }

    /**
     * Un id de token en blanco no se manda: iría a la URL como un segmento
     * vacío y la llamada acabaría pegándole a otra ruta del TMS, no a esta.
     */
    protected static function exigirToken(string $campo, string $valor): string
    {
        if (trim($valor) === '') {
            throw PaymentsException::credencialVacia($campo);
        }

        return trim($valor);
    }

    /**
     * Los últimos cuatro caracteres de un token, que es todo lo que puede
     * salir a un log: `paymentInstrument.id` sirve por sí solo para cobrar en
     * `/pts/v2/payments` (garantía transversal del cobro con tarjeta).
     */
    protected static function huella(string $token): string
    {
        return '…'.mb_substr($token, -4);
    }
}
