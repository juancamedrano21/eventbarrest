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
 * @property string $token_hash
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property-read OperatingUnit|null $unit
 */
class KdsDevice extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    protected $fillable = [
        'operating_unit_id',
        'name',
        'area',
        'token_hash',
        'last_seen_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (KdsDevice $device): void {
            // Cambiar cualquiera de estas tres no es editar el dispositivo,
            // es fabricar otro encima del rastro del primero. vendor_id lo
            // frena antes BelongsToVendor; se queda en la lista para que la
            // regla se lea completa aquí y no dependa del orden de los hooks.
            foreach (['token_hash', 'operating_unit_id', 'vendor_id'] as $columna) {
                if ($device->isDirty($columna)) {
                    throw new KitchenException(
                        'Un dispositivo no se muda de puesto ni cambia de token: revócalo y enrólalo de nuevo.',
                        'kds_device_identity_immutable',
                    );
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'area' => DispatchArea::class,
            'last_seen_at' => 'datetime',
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
     * @return BelongsTo<OperatingUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'operating_unit_id');
    }
}
