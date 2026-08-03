<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Models;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La tablet enrolada: una identidad que no es una persona.
 *
 * Todo lo demás en la plataforma entra como alguien —un cajero, un
 * encargado— y por eso puede cambiar de sitio. Esta fila entra como un
 * SITIO: la pantalla clavada en la ventanilla del puesto norte, que atiende
 * quien esté de turno sin teclear nada. De ahí sale su regla más dura: el
 * dispositivo no se muda. Si la tablet pasa a otro puesto se revoca y se
 * enrola de nuevo, porque una tablet que cambia de puesto en caliente
 * dejaría un rastro histórico mintiendo sobre quién despachó qué.
 *
 * @property int $id
 * @property int $operating_unit_id
 * @property int $vendor_id
 * @property string $name
 * @property DispatchArea|null $area Null = vigila las dos áreas del puesto
 * @property string|null $device_identity Null = se dio de alta sin puente (un navegador)
 * @property string $token_hash
 * @property Carbon|null $last_seen_at
 * @property int|null $battery_percent Null = no lo sabemos, que NO es cero
 * @property bool|null $battery_charging
 * @property Carbon|null $battery_at
 * @property Carbon|null $revoked_at
 * @property-read OperatingUnit|null $unit
 */
class KdsDevice extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    /**
     * Por debajo de aquí y sin cargador, la tablet no llega al final del
     * servicio. Es un número de operación y no de electrónica: da margen
     * para que alguien cruce el recinto con un cable antes de que la
     * pantalla se apague en plena cola.
     */
    public const BATERIA_EN_APUROS = 20;

    protected $fillable = [
        'operating_unit_id',
        'name',
        'area',
        'device_identity',
        'token_hash',
        'last_seen_at',
    ];

    /**
     * Solo guardarReenrolamiento() la enciende, y solo mientras dura ese
     * save. Es la misma figura que el transitionWrite de KitchenTicket: el
     * guard no se relaja, se le abre UNA puerta con nombre para que en el
     * sitio de la llamada se lea qué se está haciendo.
     */
    private bool $reenrolando = false;

    protected static function booted(): void
    {
        static::updating(function (KdsDevice $device): void {
            // Cambiar cualquiera de estas tres no es editar el dispositivo,
            // es fabricar otro encima del rastro del primero. vendor_id lo
            // frena antes BelongsToVendor; se queda en la lista para que la
            // regla se lea completa aquí y no dependa del orden de los hooks.
            //
            // El token sale de la lista SOLO durante un reenrolamiento, que
            // es el caso que la regla nunca contempló: la tablet que se
            // vuelve a colgar en SU puesto y vuelve a dar el PIN. Ahí no se
            // está fabricando otro dispositivo, se está renovando el secreto
            // del mismo — y por eso el puesto y el comercio siguen sin poder
            // moverse ni con la bandera puesta.
            $inmutables = $device->reenrolando
                ? ['operating_unit_id', 'vendor_id']
                : ['token_hash', 'operating_unit_id', 'vendor_id'];

            foreach ($inmutables as $columna) {
                if ($device->isDirty($columna)) {
                    throw new KitchenException(
                        'Un dispositivo no se muda de puesto ni cambia de token: revócalo y enrólalo de nuevo.',
                        'kds_device_identity_immutable',
                    );
                }
            }

            // La identidad no cambia NUNCA, ni reenrolando. Es la respuesta a
            // «qué aparato es este», y una fila que cambiase de aparato se
            // llevaría consigo el rastro de las comandas que despachó el
            // anterior. Una tablet distinta es una fila distinta.
            if ($device->isDirty('device_identity')) {
                throw new KitchenException(
                    'La identidad de un dispositivo no se reescribe: enrola el aparato nuevo aparte.',
                    'kds_device_identity_immutable',
                );
            }
        });
    }

    /**
     * La única puerta por la que se reescribe el token de una tablet que ya
     * existe: la que se descolgó y se volvió a colgar en su mismo puesto.
     *
     * El llamador coloca antes lo que el alta acaba de decidir —token nuevo,
     * nombre, área, y `revoked_at` a null si venía revocada— y esto lo
     * persiste. Se reutiliza la fila y no se crea otra porque una tablet que
     * se recuelga es la misma tablet: su historial —qué comandas empezó,
     * cuáles dio por listas— tiene que seguir siendo suyo.
     */
    public function guardarReenrolamiento(): void
    {
        $this->reenrolando = true;

        try {
            $this->save();
        } finally {
            $this->reenrolando = false;
        }
    }

    protected function casts(): array
    {
        return [
            'area' => DispatchArea::class,
            'last_seen_at' => 'datetime',
            // 'integer' y 'boolean' respetan el null: castean lo que hay y
            // dejan el hueco en su sitio. Con ellos, un `=== null` distingue
            // «no lo sé» de «está a cero», que es toda la gracia de estas
            // tres columnas.
            'battery_percent' => 'integer',
            'battery_charging' => 'boolean',
            'battery_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Qué puestos ve esta tablet. Hoy es siempre el suyo y nada más, pero la
     * consulta del tablero se escribe desde el primer día con un whereIn
     * sobre lo que devuelve esto: el día que una cocina central despache
     * para tres barras, añadir la tabla pivote será aditivo y no habrá que
     * reescribir el tablero ni la API.
     *
     * @return array<int, int>
     */
    public function unidadesVigiladas(): array
    {
        return [$this->operating_unit_id];
    }

    /** Revocada = no entra. El middleware pregunta esto en cada petición. */
    public function estaRevocada(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * ¿Ha llegado a decirnos alguna vez cuánta batería le queda?
     *
     * Existe para que el panel no tenga que escribir `!== null` sobre una
     * columna donde el null significa algo muy concreto. Quien no lo sabe se
     * pinta en gris —«sin dato»— y jamás en rojo: avisar de una batería
     * agotada que nadie ha medido es la forma más rápida de que se deje de
     * mirar el aviso de verdad.
     */
    public function sabeSuBateria(): bool
    {
        return $this->battery_percent !== null;
    }

    /**
     * La que hay que ir a enchufar.
     *
     * Cargando NO está en apuros aunque marque 4 %: ya hay un cable puesto y
     * el aviso solo serviría para mandar a alguien a un puesto donde el
     * problema está resuelto.
     */
    public function bateriaEnApuros(): bool
    {
        return $this->battery_percent !== null
            && $this->battery_percent <= self::BATERIA_EN_APUROS
            && $this->battery_charging !== true;
    }

    /**
     * Cuánto hace que se midió, en segundos, o null si nunca se midió.
     *
     * El panel lo necesita porque el nivel y su hora se leen juntos o no se
     * leen: un 8 % de hace seis horas es una tablet que ya se apagó, y un
     * 8 % de hace tres segundos es una carrera con un cable en la mano.
     */
    public function antiguedadDeLaBateria(): ?int
    {
        // Carbon 3 devuelve float aquí; el panel pinta segundos enteros.
        return $this->battery_at === null
            ? null
            : (int) $this->battery_at->diffInSeconds(absolute: true);
    }

    /**
     * @return BelongsTo<OperatingUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'operating_unit_id');
    }
}
