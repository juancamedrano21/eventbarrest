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

    /** Diez a ciegas es mucho más de lo que falla un dedo, y poquísimo para adivinar 10^6. */
    private const INTENTOS_MAXIMOS = 10;

    private const MINUTOS_DE_BLOQUEO = 15;

    public function __invoke(string $codigo, string $pin, string $deviceName, ?DispatchArea $area): EnrolledDevice
    {
        // El código se dicta y se teclea: llega con guiones, con espacios y
        // en minúscula. Todo eso es el mismo código.
        $codigo = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));

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

        return DB::transaction(function () use ($tenant, $vendor, $unidad, $deviceName, $area): EnrolledDevice {
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
                    fn (): KdsDevice => KdsDevice::create([
                        'operating_unit_id' => $unidad->id,
                        'name' => $this->nombre($deviceName),
                        'area' => $area,
                        'token_hash' => hash('sha256', $claro),
                    ]),
                ),
            );

            return EnrolledDevice::from($claro, $device);
        });
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
