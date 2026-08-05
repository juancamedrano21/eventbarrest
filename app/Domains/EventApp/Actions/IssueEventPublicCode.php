<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\Exceptions\EventAppException;
use App\Domains\EventManagement\Models\Event;

/**
 * Le pone al evento el código con el que su app lo reconoce.
 *
 * El código no es un secreto y no pretende serlo: viaja COMPILADO en el
 * binario que se publica en la tienda, así que lo lleva encima cualquiera
 * que instale la app. Lo único que hace es elegir evento, y detrás de esa
 * puerta no hay nada que escribir: tres endpoints de solo lectura con lo
 * mismo que el festival tiene impreso en un cartel.
 *
 * Por eso importa poco que sea adivinable y mucho que sea DICTABLE: se canta
 * por teléfono para configurar un build, se teclea en la pantalla oculta de
 * «cambiar el servidor» de una app de depuración, y se lee en voz alta en
 * una llamada de montaje. De ahí el mismo alfabeto recortado del código del
 * KDS: sin O ni 0, sin I ni 1 ni l.
 *
 * Es idempotente a conciencia: si el evento ya tiene código, devuelve el
 * suyo. Emitir uno nuevo dejaría inservibles todas las apps ya instaladas,
 * que llevan el viejo dentro y no pueden cambiarlo sin pasar por tienda.
 * Para cambiarlo hay que pedirlo explícitamente, con un código a mano.
 */
class IssueEventPublicCode
{
    /** Sin O/0 ni I/1/l: el código se dicta y se teclea también a mano. */
    public const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LARGO = 8;

    /** Tope del reintento: si ocho caracteres chocan cinco veces, algo va mal. */
    private const INTENTOS = 5;

    private const LARGO_MINIMO = 4;

    private const LARGO_MAXIMO = 16;

    /**
     * El código del evento. Sin `$deseado` es idempotente y no toca nada si
     * ya lo tiene; con `$deseado` se REEMPLAZA, que es la puerta del código
     * de vanidad —«BOCAO26» en el cartel— y la única forma de cambiarlo.
     *
     * El de vanidad no se genera nunca solo: lleva letras que el alfabeto
     * dictable excluye a propósito (la O de BOCAO), así que es una decisión
     * de marketing que alguien toma a mano y asume, no algo que el sistema
     * pueda inventar.
     */
    public function __invoke(Event $event, ?string $deseado = null): string
    {
        if ($deseado !== null) {
            $codigo = $this->codigoElegido($deseado, $event);
        } else {
            $existente = $event->getAttribute('public_code');

            if (is_string($existente) && $existente !== '') {
                return $existente;
            }

            $codigo = $this->codigoLibre();
        }

        $event->setAttribute('public_code', $codigo);
        $event->save();

        return $codigo;
    }

    /**
     * El código tal como lo compara todo el mundo: sin separadores y en
     * mayúscula, para que «bocao-26» y «BOCAO26» sean el mismo evento.
     *
     * Es público y estático porque lo llaman DOS sitios que no se pueden
     * separar: la puerta que resuelve el evento y la llave del limitador de
     * peticiones. Si esos dos normalizaran distinto, el freno contaría cubos
     * que la consulta no usa y dejaría de frenar — es exactamente el fallo
     * que ya se pagó en `pos-login` con las mayúsculas del usuario.
     *
     * Acepta mixed porque quien llama es a veces un parámetro de ruta y a
     * veces la entrada cruda de una petición: lo que no sea escalar es una
     * cadena vacía, nunca un error 500 antes de validar.
     */
    public static function normalizar(mixed $valor): string
    {
        $texto = is_scalar($valor) ? (string) $valor : '';

        $limpio = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $texto));

        // Cortado al largo máximo de la columna: sin esto, una petición con
        // cien mil caracteres en la URL viajaría entera hasta la consulta y
        // hasta la llave del limitador, y ninguna de las dos ganaría nada.
        return mb_substr($limpio, 0, self::LARGO_MAXIMO);
    }

    /**
     * Un código que hoy no tiene nadie. Sin el scope de cuenta: el índice es
     * global porque el teléfono resuelve el código sin saber a qué cuenta
     * pertenece el festival, así que la colisión también hay que buscarla en
     * toda la plataforma, no solo en la cuenta activa.
     */
    public function codigoLibre(): string
    {
        for ($intento = 0; $intento < self::INTENTOS; $intento++) {
            $codigo = $this->generar();

            if (! $this->ocupado($codigo)) {
                return $codigo;
            }
        }

        throw EventAppException::codigoAgotado();
    }

    private function codigoElegido(string $deseado, Event $event): string
    {
        $codigo = self::normalizar($deseado);

        if (mb_strlen($codigo) < self::LARGO_MINIMO) {
            throw EventAppException::codigoInvalido($deseado);
        }

        // El suyo no colisiona consigo mismo: repetir la orden con el mismo
        // código tiene que ser inofensiva.
        if ($codigo !== $event->getAttribute('public_code') && $this->ocupado($codigo)) {
            throw EventAppException::codigoOcupado($codigo);
        }

        return $codigo;
    }

    private function ocupado(string $codigo): bool
    {
        return Event::query()->withoutTenancy()
            ->where('public_code', $codigo)
            ->exists();
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
