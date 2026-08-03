<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventVendor;

use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Kitchen\Queries\KitchenTimings;
use App\Domains\Kitchen\Queries\KitchenTimingsReport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventVendor\Concerns\AuthorizesEventVendorPanel;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Cuánto esperó la gente en los puestos de SU comercio, en la puerta del
 * encargado.
 *
 * Es el mismo informe que ve el organizador en /event-panel y sale de la
 * misma consulta y del mismo parcial: dos copias de esta pantalla acabarían
 * divergiendo, y entonces el organizador y el comercio leerían cifras
 * distintas del mismo puesto — la peor forma posible de romper la confianza
 * en un dato que los dos usan para hablar entre ellos.
 *
 * Lo que sí cambia es el tono. Al organizador se le informa para que compare
 * comercios; al encargado se le AYUDA a arreglar su noche, así que el
 * veredicto no dice «este puesto va lento», dice qué hacer: poner una mano
 * más, revisar un plato o mirar la cobertura.
 */
class TimingsController extends Controller
{
    use AuthorizesEventVendorPanel;

    public function __invoke(Request $request, KitchenTimings $timings): View
    {
        // Los tiempos son un reporte de unidad: el mismo permiso que abre el
        // detalle de una venta. Almacén compra y recibe; no lee esto.
        $record = $this->comercioDe($request, Permission::ReportsViewUnit);

        // El aislamiento de esta pantalla NO se escribe aquí: SetTenantContext
        // deja fijado el comercio del usuario y VendorScope filtra los puestos
        // por él. Pero ese scope falla ABIERTO a propósito —sin comercio
        // activo enseña la cuenta entera, que es legítimo para el equipo del
        // organizador y sería una fuga en esta puerta—, así que lo que sí se
        // escribe aquí es la exigencia de que el contexto esté puesto.
        abort_unless(app(VendorContext::class)->id() === $record->id, 403);

        /** @var array<int, int> $unidades */
        $unidades = EventOutlet::query()->orderBy('name')->pluck('id')->all();

        $desde = $this->jornada($request);
        $hasta = $desde->copy()->addDay();

        $informe = $timings->forUnits($unidades, $desde, $hasta);

        return view('event-vendor.timings', [
            'vendor' => $record,
            'informe' => $informe,
            'consejo' => $this->consejo($informe),
            'tz' => (string) config('app.business_timezone'),
            // La jornada que se está mirando y sus vecinas, para moverse sin
            // escribir fechas a mano: casi siempre se mira hoy, y de vez en
            // cuando la noche de ayer con la cabeza fría.
            'dia' => $desde,
            'esHoy' => $desde->equalTo($this->hoy()),
            'urlDe' => fn (CarbonInterface $otro): string => route('event-vendor.timings', [
                'dia' => $otro->copy()->setTimezone((string) config('app.business_timezone'))->format('Y-m-d'),
            ]),
        ]);
    }

    /**
     * La jornada que se pide por la URL, o la de hoy.
     *
     * El corte de día va en hora de RD y no en UTC: una venta de la una de
     * la madrugada del domingo pertenece a la noche del sábado para todo el
     * mundo menos para el reloj del servidor.
     *
     * Una fecha rota en la URL no merece una pantalla de error — se cae a
     * hoy, que es lo que el encargado venía a ver de todas formas.
     */
    private function jornada(Request $request): CarbonInterface
    {
        $pedido = trim((string) $request->query('dia', ''));
        $tz = (string) config('app.business_timezone');

        if ($pedido !== '' && Carbon::hasFormat($pedido, 'Y-m-d')) {
            return Carbon::parse($pedido, $tz)->startOfDay()->utc();
        }

        return $this->hoy();
    }

    private function hoy(): CarbonInterface
    {
        return today((string) config('app.business_timezone'))->utc();
    }

    /**
     * Qué hacer con lo que dice el informe, escrito para quien puede hacerlo.
     *
     * El orden importa: la red se mira PRIMERO. Si lo que más pesa es el
     * retraso de sincronización, cualquier consejo sobre la cocina manda al
     * encargado a apretar a su gente por un problema que está en la antena —
     * que es exactamente el error contra el que avisa el ADR-009.
     *
     * @return array{tono: string, titulo: string, texto: string}|null
     */
    private function consejo(KitchenTimingsReport $informe): ?array
    {
        if ($informe->isEmpty()) {
            return null;
        }

        $cuello = $informe->cuelloDeBotella();

        // Sin mediana no hay diagnóstico: con cuatro comandas cualquier
        // consejo es una corazonada disfrazada de dato.
        if ($cuello === null || ! $cuello->enoughData()) {
            return [
                'tono' => 'gris',
                'titulo' => 'Todavía hay pocas comandas para sacar conclusiones',
                'texto' => 'Con este puñado de ventas cualquier cifra la manda un solo plato. Vuelve cuando la noche haya arrancado y aquí tendrás de qué tirar.',
            ];
        }

        if ($informe->elCuelloEsDeLaRed()) {
            return [
                'tono' => 'sky',
                'titulo' => 'Arregla la señal antes que nada',
                'texto' => 'Acerca la tableta al router o pídele al organizador cobertura en tu zona. Apretar a tu gente por esto no cambiaría nada: mientras el POS no sincroniza, en tu ventanilla nadie sabe siquiera que ese pedido existe.',
            ];
        }

        if ($cuello->label === KitchenTimingsReport::COLA) {
            return [
                'tono' => 'ambar',
                'titulo' => 'Te faltan manos, no destreza',
                'texto' => 'Lo que más pesa es el rato que la comanda pasa esperando a que alguien la coja. Tu gente cocina bien; lo que no hay es quien empiece. Una persona más en las horas fuertes se nota aquí antes que en ninguna otra cifra.',
            ];
        }

        return [
            'tono' => 'ambar',
            'titulo' => 'Empiezan rápido; lo que tarda es el plato',
            'texto' => 'Tus comandas se cogen pronto y el tiempo se va cocinando. Mira qué plato es el que estira la noche: lo que se pueda dejar adelantado antes de la hora fuerte se paga solo.',
        ];
    }
}
