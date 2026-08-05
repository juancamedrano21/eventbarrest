<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventApp\Support\CacheDeRespuesta;
use App\Domains\EventApp\Support\UrlAlcanzable;
use App\Domains\EventApp\Support\VocabularioPublico;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quién vende en el festival y dónde está cada uno: la pantalla de Menús
 * antes de entrar en ninguna carta.
 *
 * Se filtra por PARTICIPACIÓN y no por cuenta. Un organizador con dos
 * festivales tiene todos sus comercios en el mismo tenant, así que
 * TenantScope no separa nada aquí: lo que dice quién sale en esta lista es
 * la tabla `event_vendor`, y sin ella la app de un evento enseñaría los
 * comercios del otro.
 */
class EventAppVendorsController extends EventAppController
{
    public function __invoke(Request $request): Response
    {
        $evento = $this->evento($request);

        // Las tres consultas de esta pantalla —participación, comercios,
        // puestos— son las que más se repiten de las tres puertas: es la
        // segunda petición de CADA arranque de la app. Por eso el cierre:
        // durante la ventana de caché no se ejecuta ninguna.
        //
        // Lo que eso cuesta, dicho aquí y no solo en CacheDeRespuesta: un
        // comercio suspendido puede seguir NOMBRADO en esta lista hasta que
        // caduque. Su carta no —la corta la puerta, que no se cachea—, así que
        // quien lo toque recibe el 404 de siempre y la app lo trata como el
        // caso normal que ya sabe tratar.
        return $this->responder($request, CacheDeRespuesta::COMERCIOS, function () use ($evento): array {
            $participantes = EventVendor::query()
                ->where('event_id', $evento->id)
                ->pluck('vendor_id');

            $comercios = Vendor::query()
                ->whereIn('id', $participantes)
                ->where('status', VendorStatus::Active)
                ->orderBy('name')
                ->get();

            $puestos = $this->puestosPorComercio($evento->id, $comercios->pluck('id')->all());

            return [
                'comercios' => $comercios->map(fn (Vendor $comercio): array => [
                    'id' => $comercio->id,
                    'nombre' => $comercio->name,
                    'logo_url' => UrlAlcanzable::desde(
                        $comercio->logo_path === null ? null : Storage::disk('public')->url($comercio->logo_path),
                    ),
                    // Un comercio sin puestos activos sale igual, con la lista
                    // vacía: sigue estando en el festival y su carta se puede
                    // mirar. Esconderlo por no tener barra montada todavía
                    // sería que la app cambie sola durante el montaje.
                    'puestos' => $puestos[$comercio->id] ?? [],
                ])->all(),
            ];
        });
    }

    /**
     * Los puestos activos de este evento, agrupados por comercio.
     *
     * Una sola consulta para todos, no una por comercio: son treinta
     * comercios en la pantalla de arranque de miles de teléfonos.
     *
     * @param  array<int, int>  $comercios
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function puestosPorComercio(int $eventoId, array $comercios): array
    {
        if ($comercios === []) {
            return [];
        }

        return EventOutlet::query()
            ->where('event_id', $eventoId)
            ->whereIn('vendor_id', $comercios)
            ->where('status', OperatingUnitStatus::Active)
            ->orderBy('name')
            ->get()
            ->groupBy('vendor_id')
            ->map(fn (Collection $delComercio): array => $delComercio->map(
                fn (EventOutlet $puesto): array => [
                    'id' => $puesto->id,
                    'nombre' => $puesto->name,
                    'tipo' => VocabularioPublico::tipo($puesto->kind),
                ],
            )->all())
            ->all();
    }
}
