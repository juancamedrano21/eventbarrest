<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Kitchen\Exceptions\KitchenException;

/**
 * Le pone al comercio el código que se teclea en la tablet.
 *
 * El código no es un secreto y no pretende serlo: viaja escrito en un papel
 * pegado a la nevera del puesto. Lo único que hace es decir de quién es la
 * tablet; quien autoriza es el PIN del puesto. Por eso importa poco que sea
 * adivinable y mucho que sea DICTABLE — se canta por teléfono en medio del
 * montaje, con música de fondo, y se teclea con guantes.
 *
 * De ahí el alfabeto recortado: sin O ni 0, sin I ni 1 ni l. Ocho
 * caracteres sobre 31 símbolos son 8,5 · 10^11 combinaciones, de sobra para
 * que la colisión sea anecdótica; aun así se comprueba, porque «anecdótico»
 * el día del festival significa un comercio que no puede colgar su tablet.
 *
 * Es idempotente a conciencia: si el comercio ya tiene código, devuelve el
 * suyo. Emitir uno nuevo dejaría inservible el papel que ya está pegado.
 */
class IssueVendorKdsCode
{
    /** Sin O/0 ni I/1/l: se dicta por teléfono y se teclea a dedo. */
    public const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LARGO = 8;

    /** Tope del reintento: si ocho caracteres chocan cinco veces, algo va mal. */
    private const INTENTOS = 5;

    public function __invoke(Vendor $vendor): string
    {
        $existente = $vendor->getAttribute('kds_code');

        if (is_string($existente) && $existente !== '') {
            return $existente;
        }

        $codigo = $this->codigoLibre();

        $vendor->setAttribute('kds_code', $codigo);
        $vendor->save();

        return $codigo;
    }

    /**
     * Un código que hoy no tiene nadie. Sin el scope de cuenta: el índice es
     * global porque la tablet resuelve el código sin saber a qué cuenta
     * pertenece, así que la colisión también hay que buscarla en toda la
     * plataforma, no solo en la cuenta activa.
     */
    public function codigoLibre(): string
    {
        for ($intento = 0; $intento < self::INTENTOS; $intento++) {
            $codigo = $this->generar();

            $ocupado = Vendor::query()->withoutTenancy()
                ->where('kds_code', $codigo)
                ->exists();

            if (! $ocupado) {
                return $codigo;
            }
        }

        throw new KitchenException(
            'No pudimos generar un código libre para el comercio. Inténtalo de nuevo.',
            'kds_code_exhausted',
            500,
        );
    }

    private function generar(): string
    {
        $ultimo = strlen(self::ALFABETO) - 1;
        $codigo = '';

        for ($i = 0; $i < self::LARGO; $i++) {
            $codigo .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $codigo;
    }
}
