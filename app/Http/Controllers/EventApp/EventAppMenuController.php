<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventApp\Support\UrlAlcanzable;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * La carta de un comercio, tal como la vería quien se para delante del
 * puesto.
 *
 * EL PRECIO QUE SALE DE AQUÍ ES EL QUE VA A COBRAR LA CAJA, con la modalidad
 * de ITBIS del comercio ya aplicada. La regla no se reimplementa: es la misma
 * de PlaceOrder —`ResolveItbisMode` para saber la modalidad, y el propio
 * `ItbisMode` para aplicarla—, porque el día que cambie el 18 % tiene que
 * cambiar en un sitio. Un comercio que vende con el impuesto POR FUERA
 * publicando su precio base sería un menú que miente por un 18 % delante de
 * una cola, y el asistente lo descubriría en la caja.
 *
 * Y `precio_cents` es un entero en centavos: la app formatea, no calcula.
 */
class EventAppMenuController extends EventAppController
{
    /**
     * Una plataforma de un solo país por ahora. Viaja en la respuesta igual,
     * porque el formateador del teléfono necesita saberlo y adivinarlo por la
     * configuración regional del aparato daría euros a un turista.
     */
    private const MONEDA = 'DOP';

    public function __invoke(Request $request): Response
    {
        $evento = $this->evento($request);
        $comercio = $request->attributes->get('event_app_vendor');

        abort_unless($comercio instanceof Vendor, 404);

        // El backstop explícito contra el fail-open de VendorScope, repetido
        // aquí a conciencia. La puerta ya lo comprobó, pero VendorScope sin
        // comercio en contexto no añade cláusula: esta consulta devolvería la
        // carta ENTERA del festival —la del competidor de al lado incluida—
        // con un 200 y sin que nada reventara. Lo que separa dos cartas no
        // puede depender de una sola línea en otro archivo.
        abort_unless(app(VendorContext::class)->check(), 403, 'La carta se sirve siempre para un comercio.');

        $modo = app(ResolveItbisMode::class)->forVendor(
            $comercio->id,
            (int) app(TenantContext::class)->id(),
        );

        // Solo los activos. Un producto desactivado DESAPARECE de la carta;
        // no se marca «agotado», que es inventario y es de otra fase. Es lo
        // contrario de lo que hace el POS, que los manda todos y los pinta en
        // gris: al cajero le sirve saber que el plato existe y hoy no se
        // sirve, y al asistente solo le sirve para pedir lo que no hay.
        $productos = Product::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('category_id');

        $categorias = Category::query()->orderBy('name')->get();

        return $this->responder($request, [
            'comercio' => [
                'id' => $comercio->id,
                'nombre' => $comercio->name,
            ],
            'categorias' => $categorias
                // Una categoría sin productos publicables no viaja: sería un
                // apartado vacío en la carta, que se lee como un fallo.
                ->filter(fn (Category $categoria): bool => $productos->has($categoria->id))
                ->map(fn (Category $categoria): array => [
                    'id' => $categoria->id,
                    'nombre' => $categoria->name,
                    'productos' => $this->productos($productos->get($categoria->id), $modo),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  Collection<int, Product>|null  $productos
     * @return array<int, array<string, mixed>>
     */
    private function productos(?Collection $productos, ItbisMode $modo): array
    {
        return ($productos ?? collect())->map(fn (Product $producto): array => [
            'id' => $producto->id,
            'nombre' => $producto->name,
            // La columna no existe todavía en el catálogo. Viaja igual, en
            // nulo, porque el contrato la promete y añadirla después no puede
            // obligar a publicar una versión nueva de la app.
            'descripcion' => null,
            'precio_cents' => $this->precio($producto, $modo),
            'moneda' => self::MONEDA,
            'foto_url' => UrlAlcanzable::desde($producto->imageUrl()),
            // Constante hoy, y por eso mismo se manda: lo que hay en la carta
            // se puede pedir. Cuando el inventario decida esto, cambia el
            // valor y no el contrato.
            'disponible' => true,
        ])->all();
    }

    /**
     * Lo que cobraría la caja por una unidad. Misma cuenta que PlaceOrder
     * para cantidad uno, con las mismas piezas: el producto exento no suma
     * impuesto, el incluido no crece y el de por fuera sí.
     */
    private function precio(Product $producto, ItbisMode $modo): int
    {
        $itbis = $producto->itbis_exempt ? 0 : $modo->itbisOf($producto->price_cents);

        return $modo->totalOf($producto->price_cents, $itbis);
    }
}
