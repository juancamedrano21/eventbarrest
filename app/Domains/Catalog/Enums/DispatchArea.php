<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Domains\Operations\Enums\OperatingUnitKind;
use Filament\Support\Contracts\HasLabel;

/**
 * De dónde sale lo que se vende. No es decorativo: decidirá qué parte del
 * catálogo ve cada POS (una barra no muestra platos) y por qué impresora
 * salen las comandas.
 */
enum DispatchArea: string implements HasLabel
{
    case Bar = 'bar';
    case Kitchen = 'kitchen';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    /**
     * Dónde cae una línea que NO congeló su área —residuo de antes de que
     * existiera `order_lines.dispatch`, o de un producto ya borrado.
     *
     * VIVE AQUÍ Y NO EN CADA CONSUMIDOR PORQUE ESTABA ESCRITA TRES VECES Y
     * SOLO DOS LA CONOCÍAN. El tablero y la búsqueda mandaban esas líneas al
     * área del puesto; el toque que mueve la comanda las buscaba con
     * `where('dispatch', $area)`, que no casa NULL con nada. Resultado: la
     * tarjeta salía pintada en la pantalla de la cocina y cada toque
     * contestaba 422 «esta área no despacha nada» — una comanda zombi
     * colgada de la pared toda la noche, sin forma humana de cerrarla.
     *
     * MIXTA SE RESUELVE HACIA COCINA A CONCIENCIA, y lo mismo el puesto que
     * no se sabe: un plato que no aparece en el tablero de cocina es un
     * cliente esperando de pie, mientras que una bebida colada entre los
     * platos es, como mucho, una molestia. Por eso el `default` y no un
     * `match` exhaustivo: el día que nazca otra modalidad de puesto,
     * preferimos que su comanda salga en cocina a que la pantalla reviente.
     */
    public static function porDefecto(?OperatingUnitKind $kind): self
    {
        return match ($kind) {
            OperatingUnitKind::Bar => self::Bar,
            default => self::Kitchen,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Bar => 'Barra',
            self::Kitchen => 'Cocina',
        };
    }
}
