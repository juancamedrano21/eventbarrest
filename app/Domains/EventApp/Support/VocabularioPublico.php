<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Support;

use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\Operations\Enums\OperatingUnitKind;

/**
 * Las palabras que salen por la puerta de la app, en español.
 *
 * El contrato publica valores en español —«activo», «cocina»— y no los
 * `value` de los enums, que están en inglés. No es cosmética: la app compara
 * contra esas cadenas, así que son parte del contrato tanto como los nombres
 * de los campos, y cambiarlas rompe teléfonos ya publicados sin que ningún
 * test de aquí se ponga rojo.
 *
 * Viven en UNA clase y no repartidas por los controladores justo por eso: el
 * vocabulario publicado es una superficie, y una superficie se mira entera
 * antes de tocarla. Tampoco se usan los `getLabel()` de los enums, que son
 * texto de PANTALLA para el panel del organizador —«En curso», «Barra»— y
 * cambian el día que a alguien no le guste cómo suena.
 */
final class VocabularioPublico
{
    /**
     * El borrador no sale nunca por la puerta —la resuelve un 404— pero está
     * en el mapa igual: un mapa incompleto es una respuesta rota el día que
     * alguien afloje esa regla.
     */
    private const ESTADOS = [
        EventStatus::Draft->value => 'borrador',
        EventStatus::Active->value => 'activo',
        EventStatus::Closed->value => 'cerrado',
        EventStatus::Settled->value => 'liquidado',
    ];

    private const TIPOS = [
        OperatingUnitKind::Bar->value => 'barra',
        OperatingUnitKind::Kitchen->value => 'cocina',
        OperatingUnitKind::Mixed->value => 'mixta',
    ];

    public static function estado(EventStatus $estado): string
    {
        return self::ESTADOS[$estado->value];
    }

    public static function tipo(OperatingUnitKind $tipo): string
    {
        return self::TIPOS[$tipo->value];
    }
}
