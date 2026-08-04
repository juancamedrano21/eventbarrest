<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\EnrolledDevice;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * El alta de una tablet: código del comercio más PIN del puesto, y a cambio
 * un token propio que ya no caduca ni se vuelve a teclear.
 *
 * Es la única puerta de la plataforma que se abre SIN cuenta activa, porque
 * quien llama no tiene ninguna: una tablet recién sacada de la caja teclea
 * ocho caracteres y no sabe —ni tiene por qué saber— de qué organizador es
 * su comercio. De ahí que la búsqueda arranque con withoutTenancy() y que
 * el contexto se fije al final, ya con el comercio identificado, para
 * escribir la fila del dispositivo dentro de su cuenta.
 *
 * SOBRE EL TIEMPO DE RESPUESTA. El fallo es uno solo e indistinguible:
 * código que no existe, PIN equivocado, puesto cerrado o comercio
 * suspendido responden lo mismo. Y cuando no hay ningún candidato contra el
 * que comprobar se hace exactamente UN Hash::check contra un hash tonto
 * precalculado, para que ese camino tarde lo mismo que el bueno. Con dos
 * excepciones conocidas, aceptadas y escritas donde se producen: un comercio
 * con puestos SIN índice tarda un bcrypt por cada uno, y un comercio en
 * penitencia contesta sin gastar ninguno. Las dos delatan por el reloj que un
 * código existe; el porqué de aceptarlo está en `intentoDelPin`.
 *
 * UN BCRYPT POR PETICIÓN, Y ESTA ES LA PARTE QUE HAY QUE LEER ENTERA.
 *
 * Aquí había un abanico: como el PIN no dice a qué puesto pertenece, se
 * probaba el bcrypt contra TODOS los puestos del comercio. Medido, ocho
 * bcrypt en UNA sola petición anónima contra un comercio de ocho barras, y
 * con veinte barras, ciento sesenta segundos de CPU por cada minuto de
 * tráfico modesto. El código del comercio está impreso y pegado en el puesto,
 * a la vista de todo el recinto, así que cualquiera pide ese abanico las veces
 * que quiera; y ninguna llave por IP lo acota, porque la IP la escribe quien
 * llama (ver bootstrap/app.php).
 *
 * RACIONAR EL ABANICO SE PROBÓ DOS VECES Y LAS DOS SALIÓ PEOR QUE EL GASTO.
 * Un techo por origen dejaba a un comercio que no había fallado nunca en 429
 * porque otros seis se equivocaron. Un contador por puesto —«dejo de
 * comprobar los puestos con cinco fallos»— dejaba al comercio ENTERO sin poder
 * colgar tabletas con el PIN CORRECTO, sin caducar nunca y por cinco
 * peticiones anónimas: medido, cinco cocineros con un dedo torpe cada uno
 * bastaban, sin atacante ninguno. Cualquier contador que sube quien ataca,
 * sobre algo que elige quien ataca, es un botón de apagado con otro nombre. En
 * una cocina de festival a las dos de la madrugada, una tablet que no se puede
 * colgar es peor que un servidor lento.
 *
 * Así que el abanico no se raciona: SE ELIMINA. Al lado del bcrypt se guarda
 * `kds_pin_index`, un HMAC-SHA256 del PIN con el secreto de la aplicación y
 * salado con el comercio. Con él, el PIN que llega localiza su puesto en una
 * comparación de cadenas y el bcrypt se gasta UNA vez, sobre ese puesto.
 *
 * CON SU CONDICIÓN, PORQUE SIN ELLA LA FRASE ES FALSA: una petición cuesta un
 * bcrypt —tenga el comercio una barra o treinta, acierte o falle— MIENTRAS SUS
 * PUESTOS TENGAN ÍNDICE AL DÍA. Los que no lo tienen siguen costando un bcrypt
 * cada uno, y son dos: los PIN emitidos antes de que la columna existiera, que
 * no se pueden indexar sin volver a emitirlos, y TODO el parque durante el rato
 * que sigue a un cambio de APP_KEY. El día que esta columna se despliegue no
 * hay una sola fila indexada en producción, así que la frase empieza siendo
 * falsa para todo el mundo y se hace verdad según entra cada tablet o según el
 * panel rota cada PIN. Nadie que ataque puede devolver un puesto a ese estado
 * —lo dice `intentoDelPin`, que es donde está la cuenta entera—, pero tampoco
 * hay ningún contador que lo abarate: ver `anotarFallo`.
 *
 * EL ÍNDICE NACE CON EL PIN, no con la primera alta buena. Lo escribe
 * `RotateOutletKdsPin`, que es donde el panel emite TODOS los PIN, en la misma
 * escritura que el hash. Cuando solo lo escribía el alta, el día del montaje
 * —todos los puestos recién emitidos, ninguno usado todavía— no había índice
 * para ninguno y cada petición anónima volvía a costar un bcrypt por puesto:
 * medido, treinta comprobaciones por intento contra un comercio de treinta
 * puestos recién creados. El alta lo sigue escribiendo, para el residuo de los
 * PIN anteriores a la columna.
 *
 * Y NINGÚN FALLO PUEDE CERRARLE LA PUERTA A QUIEN TECLEA BIEN. El freno con
 * estado sigue existiendo, pero cambió de sujeto y de consecuencia: cuenta la
 * racha A CIEGAS contra el COMERCIO, y lo que hace al llegar al tope no es
 * cerrar ningún puesto, es dejar de gastar bcrypt en los intentos que ya se sabe
 * que no abren nada. Ni el contador ni la penitencia filtran candidatos, así que
 * no hay ninguna cuenta que alguien pueda subir para dejar fuera a un cocinero.
 * El porqué entero —y los números de lo que hoy acota adivinar un PIN— está en
 * `anotarFallo`.
 *
 * EL ÍNDICE LOCALIZA, NO AUTENTICA. Quien decide sigue siendo el bcrypt: si el
 * índice señala un puesto, el PIN se comprueba igual contra su
 * `kds_pin_hash`, y si no cuadra se rechaza. Un índice que decidiera sería un
 * PIN guardado en claro con otro nombre.
 *
 * Y NUNCA SOBREVIVE A NINGUNA DE LAS TRES COSAS DE LAS QUE SE DERIVA. Junto al
 * índice se guarda la huella de las tres (`kds_pin_indexed_hash`): el comercio
 * con el que se saló, el hash del PIN que indexa y la llave. Si el PIN se rota,
 * si la APP_KEY cambia o si el índice se calculó para otro comercio, esa huella
 * deja de corresponder y el índice se ignora. Sin la primera, rotar el PIN
 * dejaría al cocinero que teclea BIEN recibiendo «revisa el código y el PIN»;
 * sin la segunda, cambiar la APP_KEY se lo haría a TODOS los cocineros de TODA
 * la plataforma a la vez; sin la tercera, el índice tendría tres entradas y su
 * huella solo cubriría dos. Las tres son la misma avería invisible que este
 * cambio viene a quitar, y ninguna depende de que ninguna acción se acuerde de
 * nada, ni de que un modelo en memoria esté al día. Ver `huellaDelIndice`.
 *
 * QUÉ SE PIERDE, DICHO SIN ADORNOS. Con la base robada Y la APP_KEY robada,
 * el índice permite recorrer el millón de PIN posibles con un HMAC cada uno:
 * la tabla entera cae en milisegundos y sin pagar un solo bcrypt. Eso es
 * cierto y es el precio. Ahora la comparación honesta:
 *
 *   - Con la base robada Y SIN la APP_KEY, el índice no dice nada: es un HMAC
 *     llaveado. Lo único que se filtra es que dos puestos DEL MISMO comercio
 *     comparten PIN, porque comparten índice.
 *   - Con la base robada y sin índice —lo que ya se podía hacer ayer— un PIN
 *     de SEIS DÍGITOS no aguanta. Son 10^6 candidatos contra bcrypt de coste
 *     12: del orden de minutos por puesto en una tarjeta gráfica alquilada, y
 *     de horas en una máquina normal. El bcrypt nunca fue lo que hacía seguro
 *     este PIN frente a una base robada; lo que hace es que no se pueda probar
 *     barato EN LÍNEA. Si la base se va, lo que toca es rotar todos los PIN, y
 *     eso ya era verdad antes de esta columna.
 *   - La APP_KEY no vive en la base: vive en el entorno. Quien la tiene firma
 *     sesiones, descifra lo cifrado y entra por la puerta grande; el PIN de
 *     una ventanilla no es lo que le preocupa.
 *
 * Y qué se gana: que una petición anónima deje de comprar CPU a granel con un
 * código que está pegado en la pared, sin ningún contador que castigue a quien
 * no falló. Esa es la operación completa.
 *
 * LA LOCALIZACIÓN LA HACE LA BASE. `senaladoPorElIndice` pregunta por
 * (vendor_id, kds_pin_index), que es exactamente el índice que crea la
 * migración: el caso normal —código y PIN buenos— lee UNA fila y gasta UN
 * bcrypt, sin hidratar los otros veintinueve puestos del comercio. Traer todas
 * las filas se ha quedado donde hace falta de verdad y solo allí: el intento que
 * no localiza a nadie, que es el que ya iba a fallar. Ver `candidatos`.
 *
 * ASUNCIÓN ACEPTADA. Cuántos puestos con PIN tiene un comercio ya no se filtra
 * por tiempo: se gasta un bcrypt tenga los que tenga. Lo único que sigue
 * costando lo que costaba son los puestos cuyo PIN es ANTERIOR a la columna;
 * ver `intentoDelPin`, que explica por qué no se pueden indexar de golpe, por
 * qué tienen que seguir entrando, y cómo se indexan solos. A ese estado no
 * puede devolver a nadie quien ataca: rotar el PIN ya deja índice.
 *
 * SOBRE LA IDENTIDAD DEL APARATO. Llega opcional, en el alta y solo en el
 * alta, y sirve para UNA cosa: no fabricar una fila nueva cada vez que la
 * misma tablet se vuelve a colgar. NO ES UNA CREDENCIAL y el orden de este
 * método lo demuestra —se mira después de que el código y el PIN hayan
 * pasado, nunca antes, y nunca en su lugar—. Si valiera para saltarse el
 * PIN, cualquiera que averiguase una cadena de dieciséis caracteres que el
 * propio aparato reparte entraría en un puesto ajeno.
 *
 * Y PORQUE NO ES UNA CREDENCIAL, aquí se decide qué cuenta como identidad.
 * El APK filtra el ANDROID_ID de fábrica y el JavaScript filtra lo suyo,
 * pero los dos corren en el aparato del que hay que desconfiar: el cuerpo
 * del alta lo escribe quien quiera con un curl. Un filtro que vive en el
 * cliente no es un filtro, así que `identidad()` vuelve a hacer el trabajo
 * entero de este lado. Lo que no pasa el filtro NO tumba el alta: se ignora
 * y la tablet se cuelga sin identidad, exactamente como una que no la tiene.
 * Rechazar el alta por una etiqueta sería castigar a un cocinero por un dato
 * que ni sabe que su tablet manda.
 */
class EnrollKdsDevice
{
    /**
     * Un bcrypt de verdad, generado una vez contra una frase que nadie
     * conoce, para gastar el mismo tiempo que gastaría un PIN real. Es una
     * constante y no un Hash::make en caliente justamente porque generar el
     * hash cuesta lo mismo que comprobarlo: hacerlo aquí duplicaría el
     * tiempo del camino sin candidatos y volvería a delatarlo.
     */
    private const HASH_TONTO = '$2y$12$O.I7qIcVxV5eGKcJmsl9LOnQdfnQgVx/CA3Q4ITl7ZDj5rWRGQFS2';

    /**
     * Identidades que no identifican a nadie porque miles de aparatos las
     * repiten. La primera es el ANDROID_ID de fábrica más famoso de Android:
     * los equipos que salieron con ese fallo se quedaron con el del aparato
     * de pruebas del fabricante en vez de sortear el suyo. La segunda es el
     * relleno de manual que traen algunos clones y emuladores.
     *
     * El APK ya filtra la primera, y aquí se vuelve a filtrar a propósito:
     * quien manda el cuerpo del alta no tiene por qué ser el APK.
     *
     * @var list<string>
     */
    private const IDENTIDADES_COMODIN = [
        '9774d56d682e549c',
        '0123456789abcdef',
    ];

    /** Diez a ciegas es mucho más de lo que falla un dedo, y poquísimo para adivinar 10^6. */
    private const INTENTOS_MAXIMOS = 10;

    private const MINUTOS_DE_PENITENCIA = 15;

    /**
     * El índice ciego de un PIN dentro de un comercio.
     *
     * Es lo que sustituye al abanico de un bcrypt por puesto: localiza el
     * puesto en O(1) para que el bcrypt se gaste una sola vez. Función RÁPIDA
     * a propósito —un HMAC, no un bcrypt—: si costase, volveríamos a tener el
     * amplificador que se acaba de quitar.
     *
     * LLAVEADO CON EL SECRETO DE LA APLICACIÓN, que no está en la base. Sin él
     * la columna sería un diccionario de PIN: seis dígitos y un SHA-256 a pelo
     * se cruzan en un segundo con una tabla precalculada de un millón de
     * entradas.
     *
     * SALADO CON EL COMERCIO, y por eso es `static` y recibe el comercio en
     * vez de leerlo de ningún sitio. Dos puestos de comercios distintos con el
     * mismo PIN dan índices distintos: ni se ven iguales en la tabla ni el
     * índice de uno sirve para buscar en el otro. Dentro del MISMO comercio sí
     * coinciden —es el precio de poder buscar por PIN— y por eso el aislamiento
     * de verdad lo sigue haciendo la consulta, que pregunta por comercio Y por
     * índice.
     *
     * Es pública porque el índice tiene que escribirlo también quien EMITE el
     * PIN (`RotateOutletKdsPin`): la derivación vive en un solo sitio, aquí,
     * junto a la comprobación que la usa.
     */
    public static function indiceDelPin(?int $vendorId, string $pin): string
    {
        return hash_hmac('sha256', ((int) $vendorId).':'.$pin, self::secreto());
    }

    /**
     * La huella de las TRES cosas de las que depende un índice: el comercio con
     * el que se saló, el hash del PIN que indexa y la LLAVE con la que se
     * derivó.
     *
     * Es lo que hace que un índice no pueda sobrevivir a ninguna de las tres. Si
     * la huella guardada no corresponde a las tres que hay ahora mismo, el índice
     * sencillamente no se usa y ese puesto vuelve al camino de antes —más caro,
     * pero abierto— hasta que se reindexe solo.
     *
     * EL COMERCIO, porque `indiceDelPin` va salado con él. Mientras la huella
     * solo ataba el hash y la llave, el índice tenía TRES entradas y la huella
     * cubría DOS: un puesto con un índice derivado de otro `vendor_id` habría
     * dado `indiceAlDia() === true`, habría quedado FUERA del camino a ciegas y
     * el índice recalculado no habría casado con él nunca — o sea, el cocinero
     * con su PIN CORRECTO recibiendo «revisa el código y el PIN», para siempre y
     * sin nada que lo explicara. Hoy no hay ninguna escritura capaz de producir
     * ese estado (el `vendor_id` de un puesto es inmutable y las dos columnas se
     * escriben juntas), pero esa defensa depende de invariantes de otros tres
     * ficheros y meter el comercio aquí la hace local y gratuita. Es exactamente
     * el hueco por el que se coló la avería de la llave.
     *
     * EL HASH, porque rotar el PIN reescribe `kds_pin_hash`. Un índice del PIN
     * VIEJO al lado del hash del NUEVO dejaría al cocinero que teclea BIEN
     * recibiendo «revisa el código y el PIN» sin que nada lo explicara.
     *
     * LA LLAVE, y esta mitad se añadió después de medir la avería. `indiceDelPin`
     * va llaveado con la APP_KEY; mientras la huella solo ataba el índice al
     * hash, cambiar la APP_KEY dejaba TODAS las huellas cuadrando: ningún puesto
     * caía al camino a ciegas, el índice recalculado con la llave nueva no casaba
     * con ninguno, y todos los cocineros de toda la plataforma recibían 422 con
     * su PIN CORRECTO, en silencio, sin caducar y sin nada en el panel que lo
     * explicara. Medido: tres puestos emitidos por el panel, se cambia
     * `app.key`, y los tres PIN buenos pasan de 201 a 422. Y el disparador no es
     * exótico: `php artisan key:generate` en un entorno nuevo, un contenedor
     * cuya APP_KEY no está fijada, restaurar un volcado en otra instalación, o
     * la rotación con `previous_keys` que la propia documentación de Laravel
     * llama segura.
     *
     * Con la llave dentro, un cambio de APP_KEY descuadra todas las huellas a la
     * vez, todos los puestos caen al camino a ciegas y se reindexan solos con la
     * primera alta buena de cada uno. Es la degradación que se quiere: lenta,
     * ruidosa en CPU y con todo el mundo dentro.
     *
     * LO QUE SE GUARDA NO ES LA LLAVE, obviamente: es un HMAC del `kds_pin_hash`
     * llaveado con ella. De la fila no se vuelve a la llave, y quien tiene la
     * fila delante tiene ya el `kds_pin_hash`, así que esta columna no le
     * esconde nada que no tuviera.
     *
     * `previous_keys` NO se consulta, y es una decisión. Probar el índice contra
     * las llaves anteriores mantendría el camino barato durante una rotación, a
     * cambio de una derivación más por intento y de un segundo camino que
     * razonar en el sitio donde ya se han roto cuatro vueltas. El camino a ciegas
     * es la red y funciona: nadie se queda fuera y el parque se cura solo según
     * entra cada tablet.
     *
     * Es pública por lo mismo que `indiceDelPin`: quien emite el PIN escribe las
     * dos columnas o no escribe ninguna.
     */
    public static function huellaDelIndice(?int $vendorId, string $pinHash): string
    {
        return hash_hmac('sha256', ((int) $vendorId).':'.$pinHash, self::secreto());
    }

    public function __invoke(
        string $codigo,
        string $pin,
        string $deviceName,
        ?DispatchArea $area,
        ?string $identidad = null,
    ): EnrolledDevice {
        // El código se dicta y se teclea: llega con guiones, con espacios y
        // en minúscula. Todo eso es el mismo código.
        $codigo = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));

        $identidad = $this->identidad($identidad);

        $vendor = $codigo === '' ? null : Vendor::query()->withoutTenancy()
            ->where('kds_code', $codigo)
            ->first();

        [$unidad, $delComercio] = $this->intentoDelPin($pin, $vendor);

        if ($vendor === null || $unidad === null) {
            // Se apunta la racha A CIEGAS del comercio, que es lo único que un
            // intento fallido identifica de verdad: trae su código. `$delComercio`
            // es null cuando el índice localizó el puesto, o sea cuando el intento
            // no fue a ciegas. Lo que hace esa cuenta al llegar al tope —y lo que
            // NO hace, que es cerrarle la puerta a nadie— está en `anotarFallo`.
            if ($vendor !== null && $delComercio !== null) {
                $this->anotarFallo($vendor, $delComercio);
            }

            throw $this->rechazo();
        }

        $tenant = $this->cuentaOperable($vendor, $unidad);

        return DB::transaction(function () use ($tenant, $vendor, $unidad, $pin, $deviceName, $area, $identidad): EnrolledDevice {
            // Una racha se rompe cuando alguien entra: el PIN bueno pone a cero
            // la cuenta del COMERCIO, que es donde vive. Lo que el freno
            // persigue son las tandas de PIN inventados, no el dedo torpe de
            // quien acabó entrando —cinco cocineros con guantes en una mañana de
            // montaje suman más que un desconocido con prisa—.
            $this->perdonarLaRacha($vendor);

            // Y de paso deja indexado el puesto. Aquí —y solo aquí, además de
            // la rotación— existe el PIN en claro, así que este es el único
            // momento en que un puesto cuyo PIN es ANTERIOR a la columna puede
            // adquirir índice: desde una migración no se puede, porque el claro
            // no está guardado en ninguna parte. Para los que ya lo tienen (o
            // sea, todos los que emitió el panel) es reescribir el mismo valor.
            //
            // Las DOS columnas se escriben juntas o no se escribe ninguna: un
            // índice sin su huella no vale, y una huella sin su índice tampoco.
            $unidad->setAttribute(
                'kds_pin_index',
                self::indiceDelPin((int) $vendor->getAttribute('id'), $pin),
            );
            $unidad->setAttribute(
                'kds_pin_indexed_hash',
                self::huellaDelIndice(
                    (int) $vendor->getAttribute('id'),
                    (string) $unidad->getAttribute('kds_pin_hash'),
                ),
            );

            $unidad->save();

            // 64 caracteres aleatorios; en la base solo queda su sha256. El
            // claro sale de aquí una vez y no vuelve a existir.
            $claro = Str::random(64);

            $device = app(TenantContext::class)->runAs(
                $tenant,
                fn (): KdsDevice => app(VendorContext::class)->runAs(
                    $vendor,
                    fn (): KdsDevice => $this->filaDelAparato($unidad, $deviceName, $area, $identidad, $claro),
                ),
            );

            return EnrolledDevice::from($claro, $device);
        });
    }

    /**
     * La fila que le toca a este aparato: la suya de siempre si ya estuvo
     * colgado en este puesto, y una nueva si no.
     *
     * Es el corazón del encargo. Sin identidad —una pantalla abierta en un
     * navegador cualquiera— no hay con qué reconocer a nadie y nace una fila,
     * exactamente como antes.
     */
    private function filaDelAparato(
        OperatingUnit $unidad,
        string $deviceName,
        ?DispatchArea $area,
        ?string $identidad,
        string $claro,
    ): KdsDevice {
        if ($identidad !== null) {
            $this->apagarloEnLosDemasPuestos($identidad, $unidad);

            $suya = KdsDevice::query()
                ->where('operating_unit_id', $unidad->id)
                ->where('device_identity', $identidad)
                ->first();

            if ($suya !== null) {
                return $this->recolgar($suya, $deviceName, $area, $claro);
            }
        }

        return KdsDevice::create([
            'operating_unit_id' => $unidad->id,
            'name' => $this->nombre($deviceName),
            'area' => $area,
            'device_identity' => $identidad,
            'token_hash' => hash('sha256', $claro),
        ]);
    }

    /**
     * La tablet vuelve a su sitio: token nuevo, revocación levantada y el
     * nombre y el área que se acaban de teclear.
     *
     * Se reutiliza la fila —y no se crea otra— porque la que se recuelga es
     * LA MISMA tablet, y `kitchen_tickets` guarda en started_by_device_id y
     * ready_by_device_id qué aparato tocó cada comanda. Una fila nueva por
     * cada reconexión parte ese rastro en trozos: al reclamar un plato que
     * nunca salió, el panel enseñaría una tablet muerta que ya no existe en
     * la ventanilla.
     *
     * Vale también para una fila REVOCADA. Que la revocación se levante sola
     * al dar el PIN no debilita nada: revocar apaga un token, y quien vuelve
     * a entrar lo hace tecleando el código y el PIN igual que la primera vez.
     * Lo contrario —que revocar dejase el aparato vetado para siempre—
     * obligaría a un botón de «desvetar» en el panel el día que alguien
     * revoque la tablet equivocada en pleno servicio.
     */
    private function recolgar(KdsDevice $device, string $deviceName, ?DispatchArea $area, string $claro): KdsDevice
    {
        $device->setAttribute('name', $this->nombre($deviceName));
        $device->setAttribute('area', $area);
        $device->setAttribute('token_hash', hash('sha256', $claro));

        // El token viejo deja de valer en el acto: es el mismo campo. La
        // tablet que se quedó sin él no puede volver a usarlo, y si alguien
        // se lo copió, tampoco.
        $device->setAttribute('revoked_at', null);

        $device->guardarReenrolamiento();

        return $device;
    }

    /**
     * Un aparato está en un sitio a la vez.
     *
     * Si esta misma identidad tiene filas vivas en OTROS puestos, son de un
     * alta anterior de donde la tablet ya no está: dejarlas vivas mantendría
     * abierta la puerta de un puesto que ese aparato dejó de atender, y el
     * panel seguiría contando una pantalla que allí no hay.
     *
     * SOLO DENTRO DE ESTE COMERCIO, y esa es la parte que hay que leer
     * despacio. Se apaga lo que hay bajo la cuenta y el comercio cuyo PIN
     * se acaba de acertar, nunca más allá. Barrer por identidad a lo ancho
     * de la plataforma sonaría más completo y sería un regalo: presentar la
     * identidad de una tablet ajena —una cadena que no es secreta— apagaría
     * la pantalla de otro sin haber tecleado un solo PIN suyo.
     */
    private function apagarloEnLosDemasPuestos(string $identidad, OperatingUnit $unidad): void
    {
        $enOtroSitio = KdsDevice::query()
            ->where('device_identity', $identidad)
            ->where('operating_unit_id', '!=', $unidad->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($enOtroSitio as $device) {
            app(RevokeKdsDevice::class)($device);
        }
    }

    /**
     * La identidad tal como se guarda, o null si lo que llegó no sirve para
     * nombrar a un aparato.
     *
     * Tres cosas se comprueban, y las tres persiguen el mismo destrozo: que
     * dos tabletas distintas acaben compartiendo fila. Es peor que no tener
     * identidad, porque una identidad repetida no deja huecos que rellenar
     * después: se pisan el token y el rastro entre ellas, en el mismo puesto
     * y sin que nadie lo note.
     *
     * FORMATO. Letras, dígitos y cuatro separadores, empezando y acabando en
     * letra o dígito. Es EXACTAMENTE el alfabeto que declara el cliente
     * (`resources/js/kds/bateria.js`), y se repite aquí por dos motivos que
     * no se contradicen: aquel no es de fiar —corre en el aparato del que
     * desconfiamos— y a la vez es el contrato de la única fuente que existe.
     * Estrecharlo de este lado «por si acaso» —a hexadecimal puro, que es lo
     * que devuelve hoy el ANDROID_ID— dejaría fuera EN SILENCIO identidades
     * legítimas del día que otra fuente mande otra cosa, y una identidad que
     * el servidor ignora es una fila duplicada que nadie ve venir. Los dos
     * lados dicen lo mismo; el que manda es este.
     *
     * LARGO. Entre 8 y 64. Por arriba manda la columna, y el tope NO se
     * recorta: truncar a 64 fundiría en una sola fila dos cadenas distintas
     * que compartieran los primeros 64 caracteres, que es precisamente el
     * fallo que se está evitando. Por abajo, hay aparatos que devuelven el
     * ANDROID_ID sin los ceros de la izquierda y sale más corto de dieciséis;
     * menos de ocho ya no nombra a nadie —«unknown», lo que contesta Android
     * cuando no quiere contestar, cae por aquí—.
     *
     * COMODINES. Cadenas que muchísimos aparatos repiten y que por tanto no
     * distinguen a ninguno. La cadena vacía y la de espacios cuentan aquí
     * también: el puente devuelve '' cuando el sistema no da el
     * identificador, y si eso llegara a la columna, TODAS las tabletas sin
     * identidad de un puesto colisionarían en el índice único.
     *
     * Se guarda en minúscula porque el hex de una misma tablet escrito en
     * mayúscula sería otra fila para el mismo aparato.
     */
    private function identidad(?string $identidad): ?string
    {
        $limpia = mb_strtolower(trim((string) $identidad));

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{6,62}[a-z0-9]$/', $limpia) !== 1) {
            return null;
        }

        if (in_array($limpia, self::IDENTIDADES_COMODIN, true)) {
            return null;
        }

        // Un solo carácter repetido —los ceros, las efes— no es un aparato:
        // es lo que devuelve un clon barato que no sorteó nada al arrancar.
        // Va por regla y no por lista porque la familia entera cabe en una.
        if (preg_match('/^(.)\1*$/', $limpia) === 1) {
            return null;
        }

        return $limpia;
    }

    /**
     * Todos los puestos del comercio que tienen PIN puesto.
     *
     * SE TRAEN TODOS, Y ESO ES DELIBERADO, pero solo se llega aquí cuando la
     * localización por índice no encontró a nadie: o sea, en el intento que ya
     * iba a fallar. El caso normal —código y PIN buenos— no pasa por esta
     * consulta y lee UNA fila por el índice de base (ver `senaladoPorElIndice`).
     *
     * Por qué no se puede acotar en SQL: un índice se declara inservible mirando
     * su HUELLA, y la huella depende del `kds_pin_hash` de la propia fila y de la
     * llave de la aplicación. Eso no se puede preguntar en un WHERE. Acotar la
     * consulta a `kds_pin_index IS NULL` dejaría fuera precisamente el caso que
     * más importa —el día que cambie la APP_KEY, cuando NINGUNA huella cuadra y
     * los puestos tienen que caer todos al camino a ciegas— y volvería a dejar a
     * cocineros con el PIN CORRECTO recibiendo 422. Traer las filas es el precio
     * de esa red, y lo paga quien falla, nunca quien acierta.
     *
     * NI EL ESTADO NI LA PENITENCIA FILTRAN AQUÍ, y esa es la corrección que más
     * importa. Un PIN correcto contra un puesto cerrado se rechaza igual, pero
     * más abajo y sin gastarle intentos a nadie. Y la penitencia dejó de sacar
     * puestos de esta lista: mientras filtraba, la cuenta que sube quien prueba a
     * ciegas podía dejar a un cocinero fuera con su PIN bueno, que es la avería
     * que este fichero ya echó tres veces con tres nombres distintos.
     *
     * @return Collection<int, OperatingUnit>
     */
    private function candidatos(?Vendor $vendor): Collection
    {
        if ($vendor === null) {
            return $this->ninguno();
        }

        return OperatingUnit::query()->withoutTenancy()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('kds_pin_hash')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ningún candidato, con el tipo puesto para el analizador.
     *
     * @return Collection<int, OperatingUnit>
     */
    private function ninguno(): Collection
    {
        /** @var Collection<int, OperatingUnit> $vacia */
        $vacia = new Collection;

        return $vacia;
    }

    /**
     * Qué hace este PIN: qué puesto ABRE, y si hubo que buscarlo a ciegas.
     *
     *   [$unidad, $delComercio]
     *     $unidad      — el puesto que el PIN abre, o null si no abre ninguno.
     *     $delComercio — los puestos del comercio, cargados, cuando el índice NO
     *                    localizó a nadie y hubo que mirar a ciegas; null cuando
     *                    el índice sí localizó. Es lo que distingue una racha a
     *                    ciegas de un intento contra un puesto identificado, y
     *                    lo único que puede sumar a la cuenta del comercio.
     *
     * UN SOLO BCRYPT MIENTRAS EL COMERCIO ESTÉ INDEXADO. El índice ciego dice a
     * qué puesto preguntar y el bcrypt se gasta ahí, una vez. Si no señala a
     * ninguno y no queda ningún puesto sin índice, se gasta contra el hash tonto:
     * mismo coste, mismo tiempo, mismo rechazo, y por eso un código que no existe
     * no se distingue de un PIN equivocado.
     *
     * Y si el índice señala pero el bcrypt no cuadra, se rechaza SIN mirar a
     * nadie más. El índice localiza, el bcrypt decide; buscar por otro lado
     * después de un índice acertado sería volver a abrir el abanico por la
     * puerta de atrás. Ese caso, además, es inalcanzable para quien no sabe el
     * PIN: el índice se deriva del propio PIN, así que si señala, es el PIN. Por
     * eso no suma a ninguna cuenta —no hay racha que contar en un camino al que
     * solo llega quien ya sabe el número—.
     *
     * EL CAMINO DE LOS PUESTOS SIN ÍNDICE VÁLIDO, que es la parte incómoda y va
     * escrita. El índice se deriva del PIN EN CLARO y el PIN en claro no está
     * guardado en ninguna parte: solo su bcrypt. Un puesto llega aquí de dos
     * maneras, y las dos tienen que SEGUIR ENTRANDO:
     *
     *   - Su PIN se emitió antes de que la columna existiera. No se puede
     *     indexar ni desde una migración ni desde aquí sin volver a emitirlo.
     *   - La APP_KEY cambió, así que su huella dejó de cuadrar. Entonces caen
     *     TODOS a la vez, a propósito: es preferible que el recinto entero vaya
     *     lento a que el recinto entero se quede fuera. Ver `huellaDelIndice`.
     *
     * Esos puestos cuestan lo que costaban —un bcrypt cada uno— hasta que
     * alguien teclee bien su PIN, momento en el que se indexan solos (ver
     * `__invoke`). Se eligió esto sobre las dos alternativas:
     *
     *   - Dejarlos fuera hasta que el panel rote el PIN habría dejado a cocinas
     *     con el PIN CORRECTO recibiendo «revisa el código y el PIN» sin que
     *     nada en el panel lo explicara. Es exactamente la avería invisible que
     *     este arreglo viene a quitar.
     *   - Repartir entre ellos el único bcrypt disponible habría hecho que un
     *     PIN correcto acertara una vez de cada N, gastando penitencia en cada
     *     intento.
     *
     * Fuera del cambio de llave, es un estado que solo MENGUA con el uso y al
     * que nadie que ataque puede devolver a un puesto: `RotateOutletKdsPin`
     * escribe el índice y su huella junto al hash, así que un PIN recién emitido
     * por el panel —el caso de la mañana del montaje, cuando TODOS lo son— ya
     * nace indexado. Y por eso mismo el freno de `anotarFallo` no puede
     * abaratarlo: saltarse ese abanico es rechazar sin comprobar.
     *
     * EL ORÁCULO DE TIEMPO QUE ESTO DEJA, entero y aceptado. El camino sin
     * candidatos gasta un bcrypt para tardar lo mismo que el bueno, pero hay dos
     * huecos por los que el reloj habla, y los dos son del comercio, no del PIN:
     *
     *   - En penitencia se contesta sin gastar ese bcrypt, así que un comercio
     *     conocido responde MÁS RÁPIDO que un código que no existe. Confirmar que
     *     un código existe cuesta once peticiones.
     *   - Un comercio con N puestos sin índice válido gasta N bcrypt, así que
     *     responde mucho MÁS LENTO que un código inexistente, y el reloj aproxima
     *     además cuántos puestos sin indexar tiene —o sea, si acaba de
     *     desplegarse o si le rotaron la llave—.
     *
     * Se acepta porque lo que filtra es la existencia y el estado de un código
     * que está IMPRESO Y PEGADO en la pared del puesto, a la vista del recinto:
     * quien quiere saber si existe lo lee. Lo que el reloj no dice, y es lo único
     * que importa, es nada sobre el PIN: acertar y fallar cuestan lo mismo dentro
     * de cada uno de los dos regímenes.
     *
     * @return array{0: ?OperatingUnit, 1: ?Collection<int, OperatingUnit>}
     */
    private function intentoDelPin(string $pin, ?Vendor $vendor): array
    {
        $senalado = $this->senaladoPorElIndice($pin, $vendor);

        if ($senalado instanceof OperatingUnit) {
            return [
                Hash::check($pin, (string) $senalado->getAttribute('kds_pin_hash')) ? $senalado : null,
                null,
            ];
        }

        $delComercio = $this->candidatos($vendor);

        $aCiegas = $delComercio->reject(fn (OperatingUnit $unidad): bool => $this->indiceAlDia($unidad));

        // EL FRENO, Y HASTA DÓNDE LLEGA. La penitencia se mira aquí, sobre el
        // intento entero, y lo que gobierna es TODO el bcrypt que este camino
        // gasta en contestar que no: el del hash tonto. Cuando los puestos del
        // comercio están indexados —el caso normal, porque el índice nace con el
        // PIN en `RotateOutletKdsPin`— ya se SABE que este PIN no abre nada, y
        // ese bcrypt solo existe para que el camino tarde lo mismo que el bueno.
        // En penitencia se deja de comprar y el intento cuesta cero.
        //
        // Y LO QUE LA PENITENCIA NO PUEDE AHORRAR, que es la parte que hay que
        // leer despacio porque parece un olvido y es un teorema. El abanico de
        // abajo recorre los puestos SIN índice válido, y ahí no se sabe si el PIN
        // acierta o no hasta gastar su bcrypt: el bcrypt ES la comprobación.
        // Saltárselo en penitencia dejaría fuera al cocinero de un puesto sin
        // índice —el residuo anterior a la columna, o el parque entero durante el
        // rato que sigue a un cambio de APP_KEY— con su PIN CORRECTO en la mano,
        // que es la avería prohibida y la que hundió tres vueltas de este
        // fichero. Comprobar un PIN contra N puestos que ningún índice localiza
        // cuesta N bcrypt, y no hay orden de las líneas que lo cambie: lo que
        // baja ese número es que los puestos tengan índice, no un contador. Por
        // eso el freno se anuncia por lo que hace —dejar de gastar en contestar
        // que no— y no como una protección de CPU que no puede dar. La cuenta
        // completa, con los dos regímenes medidos, está en `anotarFallo`.
        if ($aCiegas->isEmpty() && ! $this->enPenitencia($vendor)) {
            Hash::check($pin, self::HASH_TONTO);
        }

        foreach ($aCiegas as $unidad) {
            if (Hash::check($pin, (string) $unidad->getAttribute('kds_pin_hash'))) {
                return [$unidad, $delComercio];
            }
        }

        return [null, $delComercio];
    }

    /**
     * ¿El índice de este puesto corresponde al PIN que tiene puesto AHORA y a la
     * llave con la que se calcularía hoy?
     *
     * Es la comprobación que hace imposible el peor fallo de este diseño: un
     * índice que no corresponde al hash de al lado —o a la llave de hoy— y que
     * por tanto dejaría fuera a quien teclea bien. Cuesta un HMAC-SHA256 por
     * candidato —nanosegundos, no un bcrypt—, así que se puede permitir el lujo
     * de no fiarse de nadie.
     */
    private function indiceAlDia(OperatingUnit $unidad): bool
    {
        $indice = $unidad->getAttribute('kds_pin_index');
        $huella = $unidad->getAttribute('kds_pin_indexed_hash');

        if (! is_string($indice) || ! is_string($huella)) {
            return false;
        }

        return hash_equals(
            $huella,
            self::huellaDelIndice(
                (int) $unidad->getAttribute('vendor_id'),
                (string) $unidad->getAttribute('kds_pin_hash'),
            ),
        );
    }

    /**
     * El puesto que este PIN localiza dentro de su comercio, PREGUNTÁNDOSELO A
     * LA BASE.
     *
     * Es la consulta para la que existe `units_vendor_kds_pin_index_idx`
     * (vendor_id, kds_pin_index) y la razón de ser del índice ciego: el caso
     * normal —el cocinero que teclea bien— lee UNA fila y gasta UN bcrypt, sin
     * hidratar los otros veintinueve puestos del comercio. Antes se traían todas
     * las filas y se filtraba en PHP, con lo que el índice de base no llegaba a
     * ejecutarse nunca y media ganancia se quedaba en el camino.
     *
     * Se hace `get()` y no `first()` porque dos puestos del MISMO comercio con
     * el mismo PIN comparten índice —es el precio de poder buscar por PIN—; se
     * queda el primero por id cuya huella cuadra, que es lo mismo que hacía el
     * filtro en memoria.
     *
     * La igualdad la compara la base y no `hash_equals`, que es justo el cambio:
     * el índice no es un secreto que quien llama pueda calcular sin la APP_KEY, y
     * lo que se gana —no hidratar treinta modelos por petición— vale más que una
     * comparación en tiempo constante sobre un valor que no es un secreto para
     * nadie que ya esté leyendo esa tabla.
     */
    private function senaladoPorElIndice(string $pin, ?Vendor $vendor): ?OperatingUnit
    {
        if ($vendor === null) {
            return null;
        }

        $senalado = OperatingUnit::query()->withoutTenancy()
            ->where('vendor_id', $vendor->id)
            ->where('kds_pin_index', self::indiceDelPin((int) $vendor->getAttribute('id'), $pin))
            ->whereNotNull('kds_pin_hash')
            ->orderBy('id')
            ->get()
            ->first(fn (OperatingUnit $unidad): bool => $this->indiceAlDia($unidad));

        return $senalado instanceof OperatingUnit ? $senalado : null;
    }

    /**
     * El secreto con el que se llavea el índice: la APP_KEY, en bytes.
     *
     * Se decodifica el `base64:` porque lo que hay que usar son los 32 bytes
     * de la llave y no su transcripción. Si no viniera codificada —una llave
     * puesta a mano— se usa tal cual: lo que importa es que sea el secreto de
     * la aplicación, no su formato.
     */
    private static function secreto(): string
    {
        $llave = (string) config('app.key');

        if (str_starts_with($llave, 'base64:')) {
            $bytes = base64_decode(substr($llave, 7), true);

            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        }

        return $llave;
    }

    /**
     * Lo que hay que seguir siendo para que la tablet se cuelgue: cuenta al
     * corriente, comercio activo, puesto abierto y evento en pie.
     *
     * El puesto es la comprobación crítica. RemoveVendorFromEvent no borra
     * los puestos del comercio que sale del evento: los deja en Closed, con
     * su PIN intacto. Sin esta línea, el comercio al que echaron el viernes
     * seguiría colgando tabletas el sábado.
     */
    private function cuentaOperable(Vendor $vendor, OperatingUnit $unidad): Tenant
    {
        $tenant = $vendor->tenant()->first();

        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            throw $this->rechazo();
        }

        if ($vendor->status !== VendorStatus::Active) {
            throw $this->rechazo();
        }

        if ($unidad->status !== OperatingUnitStatus::Active) {
            throw $this->rechazo();
        }

        $eventId = $unidad->getAttribute('event_id');

        if ($eventId !== null) {
            $evento = Event::query()->withoutTenancy()->find($eventId);

            if ($evento === null || $evento->status->isFinished()) {
                throw $this->rechazo();
            }
        }

        return $tenant;
    }

    /**
     * ¿Está este comercio en penitencia ahora mismo?
     *
     * Se lee de UNA columna del comercio, que es de quien es el hecho. Antes
     * estaba replicada en sus puestos y se contestaba «sí» si la tenía
     * cualquiera de los treinta: el mismo dato treinta veces, en una columna
     * que el panel pintaba como «este puesto está bloqueado» sin que ninguno lo
     * estuviera. Ver la migración que la mudó.
     *
     * Un código que no existe no está en penitencia: `$vendor` es null y hay que
     * contestar como se contesta siempre, comprando el bcrypt del hash tonto,
     * para que el reloj no diga cuáles de los ocho caracteres existen.
     */
    private function enPenitencia(?Vendor $vendor): bool
    {
        $hasta = $vendor?->getAttribute('kds_blind_pause_until');

        return $hasta !== null && Carbon::parse((string) $hasta)->isFuture();
    }

    /**
     * La racha se rompe: alguien acaba de entrar con un PIN de este comercio.
     *
     * Se escribe solo si hay algo que borrar. Un montaje son veinte altas
     * buenas seguidas y ninguna tiene por qué reescribir la fila del comercio
     * para dejarla igual.
     */
    private function perdonarLaRacha(Vendor $vendor): void
    {
        if ((int) $vendor->getAttribute('kds_blind_attempts') === 0
            && $vendor->getAttribute('kds_blind_pause_until') === null) {
            return;
        }

        Vendor::query()->withoutTenancy()
            ->whereKey($vendor->getKey())
            ->update(['kds_blind_attempts' => 0, 'kds_blind_pause_until' => null]);
    }

    /**
     * Un intento a ciegas más contra este COMERCIO: al décimo, quince minutos de
     * penitencia.
     *
     * QUÉ HACE LA PENITENCIA, Y ES LO ÚNICO QUE HACE: mientras dura, un intento
     * que no localiza a ningún puesto se contesta sin gastar el bcrypt contra el
     * hash tonto (ver `intentoDelPin`). No filtra candidatos, no cierra ningún
     * puesto y no aparece en ninguna decisión sobre si alguien entra. Un cocinero
     * con su PIN CORRECTO entra igual con la penitencia encendida que con ella
     * apagada, porque su PIN localiza su puesto por el índice y ese camino ni
     * mira esta columna. Esa es la condición innegociable, y por eso el freno
     * dejó de tocar `candidatos()`.
     *
     * POR QUÉ AL COMERCIO Y NO AL PUESTO. Un intento fallido no identifica ningún
     * puesto —eso es exactamente lo que el índice ciego garantiza: el índice se
     * deriva del propio PIN, así que si señala un puesto, el bcrypt cuadra—, pero
     * sí identifica el comercio: trae su código. Una vuelta anterior apuntó la
     * cuenta al puesto que el índice señala y con eso la dejó inalcanzable, o sea
     * código muerto; la siguiente la contó bien pero la escribió REPLICADA en las
     * treinta barras, en la columna que el panel pintaba como «este puesto está
     * bloqueado», y así una tanda de PIN inventados encendía un cartel falso en
     * las treinta a la vez. La cuenta es del comercio y vive en una columna del
     * comercio; el panel enseña lo que es verdad y no ofrece ningún botón, porque
     * no hay ninguna acción que tomar.
     *
     * Y CONTAR NO ES CASTIGAR. Lo que hundió las vueltas 2, 3 y 4 fue siempre lo
     * mismo: una cuenta que sube quien ataca, sobre algo que elige quien ataca, y
     * cuya consecuencia era RECHAZAR. El código del comercio está impreso y pegado
     * en el puesto, así que esa cuenta la sube cualquiera. Aquí la cuenta es la
     * misma de siempre y sigue subiéndola cualquiera; lo que cambió es que su
     * consecuencia ya no puede negarle la entrada a nadie.
     *
     * LO QUE CUESTA ESTE FRENO, medido en escrituras: diez UPDATE por cuarto de
     * hora y comercio, no uno por intento —y UNA fila, no treinta—. En cuanto la
     * penitencia entra, este método vuelve sin escribir; los noventa intentos
     * siguientes de una racha de cien no tocan la base. Esa es la razón de poner
     * el contador a cero al entrar en penitencia: ya cobró esos diez, y cuando
     * expire quien vuelva empieza de nuevo en vez de arrancar penado al primer
     * fallo.
     *
     * El freno vive en la BASE y no en caché: CACHE_STORE es database y se vacía
     * con cualquier comando de mantenimiento.
     *
     * ================================================================
     * LO QUE ESTE FRENO NO HACE, CON NÚMEROS, PORQUE HAY QUE DECIRLO
     * ================================================================
     *
     * PRIMERO, CUÁNTA CPU AHORRA DE VERDAD, que es menos de lo que este párrafo
     * decía antes. Con los puestos del comercio indexados —el caso normal, porque
     * el índice nace con el PIN en `RotateOutletKdsPin`— un intento a ciegas
     * cuesta UN bcrypt y en penitencia CERO: eso es todo lo que hay, y es todo lo
     * que puede haber. Con puestos SIN índice válido —el residuo de los PIN
     * anteriores a la columna, y el parque entero durante el rato que sigue a un
     * cambio de APP_KEY— el intento cuesta un bcrypt por puesto sin índice, y la
     * penitencia no baja ese número ni un céntimo. Medido: diez barras sin índice
     * dan [10, 10, 10, …] con la penitencia encendida y apagada.
     *
     * NO ES UN OLVIDO Y NO SE PUEDE ARREGLAR AQUÍ. Comprobar un PIN contra un
     * puesto que ningún índice localiza cuesta un bcrypt porque el bcrypt ES la
     * comprobación: no hay forma de saber si acierta sin gastarlo. Un freno que
     * se saltara ese abanico estaría rechazando sin comprobar, y a quien
     * rechazaría es al cocinero de un puesto sin índice con su PIN CORRECTO en la
     * mano —a las dos de la madrugada y sin nada en el panel que lo explicara—,
     * que es la avería prohibida de este encargo. Lo que baja ese número no es un
     * contador: es que los puestos tengan índice, y para eso está el botón de
     * rotar el PIN del panel, que reindexa en el acto. Ver `intentoDelPin`.
     *
     * Y SEGUNDO, ESTE FRENO NO ACOTA ADIVINAR UN PIN: quien prueba sigue pudiendo
     * probar a la velocidad que le dé su conexión, solo que a partir del décimo
     * intento le sale gratis para nosotros en vez de costarnos un bcrypt. Lo que
     * hoy acota adivinar es esto:
     *
     *   - El espacio: seis dígitos con los ceros a la izquierda, 10^6 PIN
     *     posibles, o sea 500.000 intentos de media hasta acertar uno.
     *   - El limitador del controlador: cinco FALLOS por minuto y por
     *     código+origen (KdsEnrollController::FALLOS_POR_MINUTO), más el techo de
     *     volumen de la ruta, sesenta por minuto con la misma llave.
     *   - Con un origen honesto, eso son 500.000 / 5 = 100.000 minutos, del orden
     *     de SETENTA DÍAS contra un solo comercio, y con el aviso del panel
     *     encendido todo ese tiempo. Acotado de sobra.
     *   - Con el origen que hay hoy, NO. `bootstrap/app.php` confía en cualquier
     *     proxy (`trustProxies(at: '*')`, decisión de despliegue tomada y
     *     documentada), así que la IP la escribe quien llama y le basta con
     *     cambiar la cabecera en cada petición para estrenar cubo. El techo real
     *     pasa a ser su ancho de banda: a 50 peticiones por segundo, 500.000
     *     intentos son unas TRES HORAS.
     *
     * Se acepta hoy porque las dos salidas están fuera de este fichero y las dos
     * son decisiones de otro: (1) acotar `trustProxies(at:)` a los rangos del
     * borde —el túnel en local, el balanceador en Railway—, que devuelve el
     * mordisco al limitador de los cinco por minuto y deja el número en setenta
     * días; (2) más entropía que seis dígitos, que además del PIN toca la regla
     * `digits:6` del controlador, el teclado del APK y las hojas del montaje.
     * Mientras tanto, lo que hay para verlo venir es el aviso del panel del
     * organizador: dice que alguien está probando PIN contra el código de ese
     * comercio, que es verdad, y no pide ninguna acción, porque no hay ninguna
     * que tomar.
     *
     * Y NO INTENTES CERRARLO AQUÍ. Frenar las adivinanzas a ciegas exige rechazar
     * peticiones cuyo PIN no se ha comprobado, y distinguir al que adivina del
     * cocinero solo se puede por el ORIGEN. Un freno que no mira el origen y
     * rechaza igual al que teclea bien es un botón de apagado del recinto, que es
     * el mismo animal que este fichero ya echó tres veces con tres nombres. Y una
     * espera artificial tampoco vale: dormir la respuesta retiene un trabajador
     * de PHP por intento, así que multiplica por cuatro la facilidad de tumbar el
     * proceso entero, y con todos los trabajadores dormidos el cocinero con el PIN
     * correcto tampoco entra. Negar por la puerta de atrás sigue siendo negar.
     *
     * @param  Collection<int, OperatingUnit>  $puestos  los del comercio que tienen PIN puesto
     */
    private function anotarFallo(Vendor $vendor, Collection $puestos): void
    {
        // Un comercio sin ningún puesto con PIN no tiene puerta que forzar: no
        // hay racha que contar ni nada que enseñarle al organizador sobre un
        // enrolamiento que no puede ocurrir.
        if ($puestos->isEmpty()) {
            return;
        }

        if ($this->enPenitencia($vendor)) {
            return;
        }

        $fallos = 1 + (int) $vendor->getAttribute('kds_blind_attempts');

        // Una fila y una escritura, la del comercio. Antes esto era un UPDATE
        // sobre los treinta puestos por cada intento fallido, replicando el mismo
        // dato treinta veces para que el panel tuviera algo por puesto que pintar.
        Vendor::query()->withoutTenancy()
            ->whereKey($vendor->getKey())
            ->update($fallos >= self::INTENTOS_MAXIMOS
                ? [
                    'kds_blind_attempts' => 0,
                    'kds_blind_pause_until' => now()->addMinutes(self::MINUTOS_DE_PENITENCIA),
                ]
                : ['kds_blind_attempts' => $fallos]);
    }

    private function nombre(string $deviceName): string
    {
        $nombre = trim($deviceName);

        return $nombre === '' ? 'Tablet' : mb_substr($nombre, 0, 60);
    }

    /**
     * Un fallo único para todo. Distinguir «ese código no existe» de «ese
     * PIN no es» convertiría el código público en una lista de comercios de
     * la plataforma, y el PIN en algo que se puede acorralar sabiendo que
     * el resto ya está bien.
     */
    private function rechazo(): KitchenException
    {
        return new KitchenException(
            'No pudimos activar la tablet. Revisa el código del comercio y el PIN del puesto.',
            'kds_enrollment_rejected',
            422,
        );
    }
}
