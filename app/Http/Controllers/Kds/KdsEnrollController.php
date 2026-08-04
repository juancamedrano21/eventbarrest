<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kds;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\EnrolledDevice;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Models\OperatingUnit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * El alta de la tablet y su baja: las dos únicas puertas del KDS que no
 * hablan de comida.
 *
 * El alta es PÚBLICA —quien llama todavía no tiene token ni cuenta— y por
 * eso es la que hay que frenar bien.
 *
 * POR QUÉ EL FRENO SE ESCRIBE A MANO Y NO CON throttle:. Es el mismo error
 * que arrastraba el login del POS. `ThrottleRequests` cuenta TODAS las
 * peticiones, aciertos incluidos, y en un festival todas las tabletas salen
 * por el mismo NAT: la sexta tablet del montaje recibiría un 429 sin que
 * nadie se hubiera equivocado, justo el día y la hora en que menos se puede
 * parar a esperar. Aquí se cuenta SOLO lo que falla —`hit()` en el rechazo,
 * `clear()` al acertar—, que es lo que de verdad hay que frenar: alguien
 * probando PINes de seis dígitos contra un código de comercio que está
 * impreso y pegado en el puesto, a la vista de todo el recinto.
 *
 * (El `throttle:kds-enrolar` de la ruta sigue puesto como techo de volumen:
 * sesenta por minuto y código+origen, contando aciertos. El número está en
 * AppServiceProvider junto al porqué de cada mitad de la llave.)
 *
 * POR QUÉ AQUÍ NO HAY UN SEGUNDO FRENO «POR ORIGEN». Lo hubo, y era peor que
 * el agujero que tapaba: treinta fallos por minuto y origen. Como la IP la
 * escribe quien llama (ver bootstrap/app.php), ese cubo es en la práctica uno
 * solo para toda la plataforma, y basta con que seis comercios distintos
 * tecleen mal su PIN en el mismo minuto —una mañana de montaje cualquiera—
 * para que un SÉPTIMO comercio que no ha fallado ni una vez, con su código y
 * su PIN buenos, reciba 429. Un freno que castiga a quien no falló es peor
 * que no tener freno.
 *
 * Y el gasto que ese freno decía acotar ya no está aquí: lo que regalaba CPU
 * era el abanico de un bcrypt por puesto candidato dentro de EnrollKdsDevice,
 * y ese abanico ya no existe. Un índice ciego llaveado con el secreto de la
 * aplicación localiza el puesto y el bcrypt se gasta UNA vez, tenga el comercio
 * una barra o treinta. El porqué entero está allí, incluido lo que se pierde.
 *
 * Eso es lo que permite que aquí no haya ningún contador más. Racionar el
 * abanico se intentó dos veces —por origen y por puesto— y las dos veces la
 * cura fue peor: contadores que sube quien ataca, sobre un comercio que elige
 * quien ataca, y cocinas que no podían colgar su tablet con el PIN CORRECTO.
 * Un freno que castiga a quien no falló es peor que no tener freno.
 */
class KdsEnrollController extends Controller
{
    /**
     * Cinco fallos por minuto y código+origen, el mismo número que el login de
     * la plataforma.
     *
     * ES EL ÚNICO FRENO QUE PUEDE RECHAZAR UN INTENTO DEL ALTA, y conviene no
     * confundirse con el otro. La penitencia por comercio de EnrollKdsDevice no
     * rechaza nada: lo único que hace al llegar al décimo intento a ciegas es
     * dejar de gastar bcrypt en contestar que no. Es a propósito —un freno que
     * cuenta lo que sube quien ataca y encima cierra la puerta deja fuera al
     * cocinero que teclea BIEN, que es lo que hundió tres rondas de este
     * fichero—, pero significa que acotar la campaña le toca a este de aquí.
     *
     * Y este muerde solo mientras el origen sea real. Con
     * `trustProxies(at: '*')` en bootstrap/app.php la IP la escribe quien llama,
     * así que basta cambiar la cabecera en cada petición para estrenar cubo.
     * Los números de lo que eso deja abierto —y las dos salidas, las dos fuera
     * de aquí— están en el docblock de EnrollKdsDevice::anotarFallo.
     */
    private const FALLOS_POR_MINUTO = 5;

    public function enrolar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:20'],
            // Seis dígitos exactos: el PIN es numérico porque se teclea en
            // un teclado de tablet con guantes puestos.
            'pin' => ['required', 'digits:6'],
            'device_name' => ['required', 'string', 'max:80'],
            // Null = la tablet vigila las dos áreas del puesto.
            'area' => ['nullable', Rule::enum(DispatchArea::class)],
        ]);

        // Con qué se reconoce al aparato que ya estuvo colgado aquí. Es
        // OPCIONAL de verdad: solo la trae el APK, que la lee de su puente;
        // la misma pantalla abierta en un navegador no tiene ninguna y se da
        // de alta igual, con su fila nueva.
        //
        // NO ES UNA CREDENCIAL, y por eso NO está en el validate() de arriba:
        // una regla allí convierte cualquier cosa rara en un 422 y tumbaría el
        // alta entera por una etiqueta. El cocinero que teclea bien el código
        // y el PIN tiene que entrar aunque su tablet mande una identidad que
        // no valga —él ni sabe que la manda—. Lo que llega se recoge tal cual,
        // incluso si no es ni una cadena, y EnrollKdsDevice decide si cuenta
        // como identidad o si se ignora. Ese filtro vive allí y no aquí porque
        // la acción también se llama sin pasar por HTTP.
        $identidad = $request->input('device_identity');

        $llave = $this->llaveDelFreno((string) $data['codigo'], (string) $request->ip());

        // Se mira aquí —antes de llamar a la acción— para que la petición
        // frenada no llegue a gastar ni un bcrypt.
        if (RateLimiter::tooManyAttempts($llave, self::FALLOS_POR_MINUTO)) {
            return response()->json([
                'code' => 'kds_demasiados_intentos',
                'message' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($llave).' segundos.',
            ], 429);
        }

        try {
            $enrolada = app(EnrollKdsDevice::class)(
                (string) $data['codigo'],
                (string) $data['pin'],
                (string) $data['device_name'],
                filled($data['area'] ?? null) ? DispatchArea::from((string) $data['area']) : null,
                is_string($identidad) ? $identidad : null,
            );
        } catch (KitchenException $rechazo) {
            RateLimiter::hit($llave);

            throw $rechazo;
        }

        // El acierto perdona la cuenta: lo que este freno persigue son las
        // rachas a ciegas, no el dedo torpe de quien acabó entrando. Es lo
        // mismo que hace EnrollKdsDevice con la racha del comercio.
        RateLimiter::clear($llave);

        return response()->json($this->alta($enrolada), 201);
    }

    /**
     * La tablet se apaga a sí misma. Es lo que se toca al desmontar el
     * puesto o al prestarle la tablet a otro: el token deja de valer en el
     * siguiente polling y la fila se queda, porque las comandas guardan qué
     * dispositivo las empezó y cuál las dio por listas.
     */
    public function salir(Request $request): JsonResponse
    {
        $device = $request->attributes->get('kds_device');

        // Detrás de kds.device esto está siempre; el instanceof es para el
        // analizador, que solo ve un mixed saliendo de los atributos.
        if ($device instanceof KdsDevice) {
            app(RevokeKdsDevice::class)($device);
        }

        return response()->json([
            'message' => 'Esta tablet ya no entra. Para volver a usarla, actívala con el código y el PIN.',
        ]);
    }

    /**
     * El código se normaliza IGUAL que dentro de EnrollKdsDevice —sin
     * guiones, sin espacios y en mayúscula— porque si no, «abcd-1234» y
     * «ABCD1234» serían dos llaves distintas para el mismo intento y el
     * freno se saltaría escribiendo el guion una vez sí y otra no.
     */
    private function llaveDelFreno(string $codigo, string $ip): string
    {
        $limpio = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));

        return 'kds:enrolar:'.$limpio.'|'.$ip;
    }

    /**
     * Todo lo que la tablet necesita para pintar su primera pantalla sin
     * pedir nada más: quién es, dónde está y de quién es el puesto. El token
     * viaja aquí y en ningún otro sitio — en la base solo queda su sha256.
     *
     * @return array<string, mixed>
     */
    private function alta(EnrolledDevice $enrolada): array
    {
        $device = $enrolada->device;

        // La petición es pública, así que al volver del alta no hay cuenta
        // ni comercio en contexto. Estas tres filas se leen sin tenencia a
        // propósito: no se está buscando nada, se está mostrando lo que el
        // enrolamiento acaba de decidir.
        [$unidad, $vendor, $evento] = app(VendorContext::class)->runWithoutVendor(
            function () use ($device): array {
                $unidad = OperatingUnit::query()->withoutTenancy()->find($device->operating_unit_id);
                $vendor = Vendor::query()->withoutTenancy()->find($device->vendor_id);

                $evento = $unidad?->event_id === null
                    ? null
                    : Event::query()->withoutTenancy()->find($unidad->event_id);

                return [$unidad, $vendor, $evento];
            },
        );

        return [
            'token' => $enrolada->plainToken,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'area' => $device->area?->value,
            ],
            'outlet' => [
                'id' => $unidad?->id,
                'name' => $unidad?->name,
                'kind' => $unidad?->kind->value,
            ],
            'vendor' => [
                'id' => $vendor?->id,
                'name' => $vendor?->name,
            ],
            'event' => $evento === null ? null : [
                'id' => $evento->id,
                'name' => $evento->name,
            ],
        ];
    }
}
