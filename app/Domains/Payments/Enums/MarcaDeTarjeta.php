<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * La marca de una tarjeta guardada, en el vocabulario público de la app.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * UNA MARCA QUE NO RECONOCEMOS NO SE CONVIERTE EN VISA. EXISTE `Desconocida`.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Boletu hace justo eso —`mapCardType` cae silenciosamente a Visa— y en un
 * checkout es tolerable: la marca ahí es decoración. Aquí no. La marca decide
 * la REGLA DE ENCADENADO del `networkTransactionId` (lección 6 de la doc 12
 * §0.2): Visa encadena con el de la última transacción exitosa, y Mastercard,
 * Amex y Discover siempre con el del cobro original. Una Amex que el sistema
 * cree Visa encadena mal, y eso no falla al guardar: falla semanas después,
 * en un cobro que la red recategoriza o rechaza, sin nada que lo insinúe.
 *
 * Por eso el enum tiene su propio caso para «no sé», y ese caso viaja hasta
 * la app tal cual: una tarjeta con marca desconocida se pinta genérica, que
 * es honesto, en vez de pintarse Visa, que es mentira.
 *
 * Los valores salen en español y minúscula porque son parte del contrato
 * público, como `activo` o `cocina` (ver VocabularioPublico): la app compara
 * contra estas cadenas y cambiarlas rompe teléfonos ya publicados.
 */
enum MarcaDeTarjeta: string
{
    case Visa = 'visa';

    case Mastercard = 'mastercard';

    case Amex = 'amex';

    case Discover = 'discover';

    case Diners = 'diners';

    /** Ni un fallback ni un error: una respuesta honesta. Ver la cabecera. */
    case Desconocida = 'desconocida';

    /**
     * Traduce lo que dice Cybersource en `card.type`.
     *
     * Llegan CÓDIGOS NUMÉRICOS, no nombres: medido contra apitest el
     * 2026-08-07, el `GET /tms/v2/customers/…/payment-instruments/…` de una
     * Visa de prueba devuelve `"card": {"type": "001"}`. Los nombres se
     * aceptan igual porque la captura de Unified Checkout todavía no está
     * construida (cuarto slice) y no hay medida de qué manda ella; si algún
     * día manda `"visa"`, esto ya lo entiende, y si manda otra cosa, cae en
     * `Desconocida`, que es exactamente donde tiene que caer.
     *
     * Los códigos son los de la tabla de Cybersource: 001 Visa, 002
     * Mastercard, 003 American Express, 004 Discover, 005 Diners Club. Lo que
     * no esté aquí —JCB, Maestro, Carte Blanche, una marca nueva— es
     * `Desconocida` A PROPÓSITO: reconocer de más es peor que reconocer de
     * menos, porque una marca mal atribuida encadena mal.
     */
    public static function desdeCybersource(mixed $tipo): self
    {
        if (! is_string($tipo)) {
            return self::Desconocida;
        }

        return match (mb_strtolower(trim($tipo))) {
            '001', 'visa' => self::Visa,
            '002', 'mastercard', 'master card', 'eurocard' => self::Mastercard,
            '003', 'amex', 'american express', 'americanexpress' => self::Amex,
            '004', 'discover' => self::Discover,
            '005', 'diners', 'diners club', 'dinersclub' => self::Diners,
            default => self::Desconocida,
        };
    }
}
