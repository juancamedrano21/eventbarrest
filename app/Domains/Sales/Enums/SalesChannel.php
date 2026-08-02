<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * De dónde vino la venta. Se guarda como dato propio (los reportes filtran
 * por él) y además presta su letra al número de orden: P0041, M0042, W0043.
 *
 * La letra etiqueta, NO numera: la serie es una sola por comercio, así que
 * «el 41» identifica una única venta sin depender de recordar la letra.
 *
 * Hoy solo existe el POS; los demás canales esperan a que existan sus
 * puertas.
 */
enum SalesChannel: string implements HasLabel
{
    case Pos = 'pos';
    case Mobile = 'mobile';
    case Web = 'web';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pos => 'Punto de venta',
            self::Mobile => 'App móvil',
            self::Web => 'Web',
        };
    }

    /** La inicial que abre el número de orden. */
    public function letter(): string
    {
        return match ($this) {
            self::Pos => 'P',
            self::Mobile => 'M',
            self::Web => 'W',
        };
    }
}
