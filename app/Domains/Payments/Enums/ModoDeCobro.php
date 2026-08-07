<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * De dónde salen los datos de la tarjeta en un cobro.
 *
 * El modo cambia el cuerpo que se manda, no la lectura de la respuesta: eso
 * es siempre igual.
 */
enum ModoDeCobro: string
{
    /**
     * Primera vez: llega un `transientTokenJwt` capturado FUERA de este
     * servidor (webview de Cybersource). Es el cobro que además tokeniza.
     */
    case TarjetaNueva = 'tarjeta_nueva';

    /** Compra de dos toques: solo el token guardado, sin datos de tarjeta. */
    case TarjetaGuardada = 'tarjeta_guardada';

    /**
     * PAN en claro contra el sandbox, y NADA MÁS.
     *
     * Existe porque probar el cimiento servidor-a-servidor sin montar la
     * captura en webview exige mandar el 4111… de prueba. No es un camino de
     * producción ni puede llegar a serlo: un PAN que toca este servidor mete
     * a la plataforma entera en alcance SAQ D, que es exactamente lo que el
     * diseño de captura fuera del backend evita (doc 12 §0.3 y §1). La acción
     * se niega a usarlo si el host no es el de pruebas.
     */
    case PanDeSandbox = 'pan_de_sandbox';
}
