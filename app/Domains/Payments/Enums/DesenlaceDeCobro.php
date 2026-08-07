<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * Las cinco respuestas posibles a la única pregunta que importa: ¿podemos
 * despachar la comida?
 *
 * Solo `Aprobado` la contesta que sí. Todo lo demás —incluido lo que parece
 * un sí a medias, como una autorización parcial— es un no para el despacho,
 * aunque después haya que resolverlo de formas distintas.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * `Rechazado` E `Incierto` NO SON LO MISMO, Y ESA ES LA DISTINCIÓN CARA.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Un rechazo es una respuesta: Cybersource dijo que no, no se cobró nada, y
 * reintentar es gratis. Un incierto es el silencio: la llamada se cortó y la
 * tarjeta puede estar cobrada sin que lo sepamos. Reintentar ahí, en un
 * festival con mala señal, es el doble cobro. Tratarlos igual es, tarde o
 * temprano, cobrar dos veces o regalar una comida.
 *
 * Ante `Incierto` NO se reintenta a ciegas: primero se pregunta a Cybersource
 * si esa `clientReferenceInformation.code` ya existe
 * (`BuscarCobroPorReferencia`, doc 12 §4).
 */
enum DesenlaceDeCobro: string
{
    /** Dinero cobrado y confirmado. Es el único que despacha. */
    case Aprobado = 'aprobado';

    /** Ni cobrado ni descartado: alguien tiene que resolverlo. NO despacha. */
    case Pendiente = 'pendiente';

    /** El emisor o el motor de riesgo dijeron que no. Sin cobro, y se puede reintentar. */
    case Rechazado = 'rechazado';

    /**
     * No hubo respuesta: puede que el dinero se haya movido y no lo sepamos.
     * NO despacha, y sobre todo NO se reintenta sin conciliar antes.
     */
    case Incierto = 'incierto';

    /** La petición no llegó a ser una decisión de pago: bug nuestro o estado nuevo. */
    case Error = 'error';
}
