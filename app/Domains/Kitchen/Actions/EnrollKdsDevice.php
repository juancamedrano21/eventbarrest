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
 * precalculado, para que ese camino tarde lo mismo que el bueno. Esto
 * corrige un oráculo real que arrastra el POS: PosAuthController hace
 * Hash::make Y Hash::check cuando el usuario no existe —dos bcrypt— y uno
 * solo cuando existe, así que allí la respuesta LENTA significa «ese
 * usuario no existe». Aquí no.
 *
 * ASUNCIÓN ACEPTADA. El bucle sí filtra por tiempo CUÁNTOS puestos con PIN
 * tiene ese comercio: cinco candidatos tardan cinco bcrypt. Se acepta a
 * conciencia. No es un secreto —cuántas barras tiene un comercio se ve
 * andando por el recinto— y aplanarlo obligaría a comprobar siempre un
 * número fijo de hashes, lo que solo trasladaría el coste sin comprar
 * nada. Lo que sí es secreto, el PIN, no se filtra: acertar el puesto no
 * acorta el camino porque el resultado se decide al final y el bloqueo
 * cuenta igual.
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

    private const MINUTOS_DE_BLOQUEO = 15;

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

        $candidatos = $this->candidatos($vendor);
        $unidad = $this->unidadDelPin($pin, $candidatos);

        if ($vendor === null || $unidad === null) {
            // Un PIN que no acierta ningún puesto del comercio gasta intento
            // en todos ellos: no sabemos contra cuál iba, y quien prueba a
            // ciegas prueba contra el comercio entero.
            foreach ($candidatos as $candidato) {
                $this->anotarFallo($candidato);
            }

            throw $this->rechazo();
        }

        $tenant = $this->cuentaOperable($vendor, $unidad);

        return DB::transaction(function () use ($tenant, $vendor, $unidad, $deviceName, $area, $identidad): EnrolledDevice {
            // El PIN bueno limpia la cuenta de fallos del puesto: lo que el
            // freno persigue son las rachas a ciegas, no el dedo torpe de
            // quien acabó entrando.
            $unidad->setAttribute('kds_pin_failed_attempts', 0);
            $unidad->setAttribute('kds_pin_locked_until', null);
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
     * Los puestos del comercio contra los que tiene sentido comprobar el
     * PIN: los que tienen uno puesto y no están bloqueados ahora mismo.
     *
     * El estado del puesto NO filtra aquí a propósito. Un PIN correcto
     * contra un puesto cerrado se rechaza igual, pero más abajo y sin
     * gastarle intentos a nadie: quien acierta el PIN no es quien está
     * probando a ciegas, y castigarlo por que le cerraran el puesto sería
     * bloquear a la víctima.
     *
     * @return Collection<int, OperatingUnit>
     */
    private function candidatos(?Vendor $vendor): Collection
    {
        if ($vendor === null) {
            /** @var Collection<int, OperatingUnit> $ninguno */
            $ninguno = new Collection;

            return $ninguno;
        }

        return OperatingUnit::query()->withoutTenancy()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('kds_pin_hash')
            ->orderBy('id')
            ->get()
            ->reject(fn (OperatingUnit $unidad): bool => $this->estaBloqueado($unidad))
            ->values();
    }

    /**
     * @param  Collection<int, OperatingUnit>  $candidatos
     */
    private function unidadDelPin(string $pin, Collection $candidatos): ?OperatingUnit
    {
        if ($candidatos->isEmpty()) {
            // Ver el docblock de la clase: sin este bcrypt, el código que no
            // existe contestaría al instante y sería enumerable.
            Hash::check($pin, self::HASH_TONTO);

            return null;
        }

        foreach ($candidatos as $unidad) {
            if (Hash::check($pin, (string) $unidad->getAttribute('kds_pin_hash'))) {
                return $unidad;
            }
        }

        return null;
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

    /** ¿Está el puesto en penitencia ahora mismo? */
    private function estaBloqueado(OperatingUnit $unidad): bool
    {
        $hasta = $unidad->getAttribute('kds_pin_locked_until');

        return $hasta !== null && Carbon::parse((string) $hasta)->isFuture();
    }

    /**
     * El freno vive en la BASE y no en caché: CACHE_STORE es database y se
     * vacía con cualquier comando de mantenimiento. Al bloquear se pone el
     * contador a cero porque el bloqueo ya cobró esos diez intentos —cuando
     * expire, quien vuelva empieza de nuevo, no bloqueado al primer fallo.
     */
    private function anotarFallo(OperatingUnit $unidad): void
    {
        $fallos = (int) $unidad->getAttribute('kds_pin_failed_attempts') + 1;

        if ($fallos >= self::INTENTOS_MAXIMOS) {
            $unidad->setAttribute('kds_pin_failed_attempts', 0);
            $unidad->setAttribute('kds_pin_locked_until', now()->addMinutes(self::MINUTOS_DE_BLOQUEO));
        } else {
            $unidad->setAttribute('kds_pin_failed_attempts', $fallos);
        }

        $unidad->save();
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
