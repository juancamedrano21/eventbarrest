<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Models;

use App\Domains\EventApp\Exceptions\EventAppException;
use App\Domains\Payments\Enums\MarcaDeTarjeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una tarjeta que el asistente dejó guardada.
 *
 * SIN BelongsToTenant, como la cuenta de la que cuelga y por lo mismo: el
 * asistente es un actor de PLATAFORMA y su tarjeta viaja con él de un
 * festival al siguiente. Registrado como excepción en ModelConventionTest.
 *
 * En la fila no hay tarjeta: hay ids de token de Cybersource y lo justo para
 * pintarla. El PAN vive en la bóveda y no toca este servidor.
 *
 * @property int $id
 * @property int $event_app_account_id
 * @property string $customer_token_id
 * @property string $payment_instrument_id
 * @property string|null $instrument_identifier_id
 * @property MarcaDeTarjeta $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property bool $is_default
 * @property string $verification_reference
 * @property string|null $verification_transaction_id
 * @property Carbon|null $verification_voided_at
 * @property Carbon|null $consent_at
 * @property string $consent_version
 * @property string|null $consent_ip
 * @property-read EventAppAccount|null $cuenta
 */
class EventAppCard extends Model
{
    /**
     * Las columnas que identifican la credencial en la bóveda, y que por eso
     * no se pueden reescribir después de crear la fila.
     *
     * El motivo no es la pureza: la regla «404 o 410 = ya no está» se aplica a
     * la PAREJA (customer, payment instrument), porque la ruta del TMS las
     * lleva a las dos. Una fila con el customer de otra tarjeta haría que un
     * 404 —«ese customer no existe»— se leyera como «esta tarjeta ya no
     * está», se borrara la fila y quedara el token VIVO sin nada que lo
     * nombre: la tarjeta fantasma que todo el slice existe para evitar. La
     * pareja se escribe una sola vez, de la misma respuesta del mismo cobro, y
     * ya no cambia. Ver `AccionSobreLaBoveda`.
     *
     * @var list<string>
     */
    private const CREDENCIAL_INMUTABLE = [
        'event_app_account_id',
        'customer_token_id',
        'payment_instrument_id',
    ];

    protected $fillable = [
        'event_app_account_id',
        'customer_token_id',
        'payment_instrument_id',
        'instrument_identifier_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'verification_reference',
        'verification_transaction_id',
        'verification_voided_at',
        'consent_at',
        'consent_version',
        'consent_ip',
    ];

    protected function casts(): array
    {
        return [
            'brand' => MarcaDeTarjeta::class,
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
            'verification_voided_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EventAppCard $tarjeta): void {
            $tocadas = array_intersect(
                self::CREDENCIAL_INMUTABLE,
                array_keys($tarjeta->getDirty()),
            );

            if ($tocadas !== []) {
                throw EventAppException::credencialDeTarjetaInmutable(implode(', ', $tocadas));
            }
        });
    }

    /**
     * @return BelongsTo<EventAppAccount, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(EventAppAccount::class, 'event_app_account_id');
    }

    /**
     * El orden en que la app las recibe: primero la de por defecto, y dentro
     * de eso las más antiguas antes. Es estable a propósito — una lista que
     * se reordena sola entre peticiones hace que el asistente toque la
     * tarjeta que no era.
     *
     * @param  Builder<EventAppCard>  $query
     * @return Builder<EventAppCard>
     */
    public function scopeEnOrdenDeApp(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * Las altas cuyo cobro de verificación NO se pudo devolver.
     *
     * Es el punto de entrada de la reconciliación: cada fila trae su
     * `verification_reference` y su `verification_transaction_id`, que es
     * exactamente lo que necesitan `BuscarCobroPorReferencia` (para saber qué
     * pasó de verdad con ese cobro) y `AnularCobro` (para volver a
     * intentarlo). Antes de existir esta consulta el único rastro era un
     * `Log::warning`, o sea: había que saber que había pasado para poder
     * buscarlo.
     *
     * @param  Builder<EventAppCard>  $query
     * @return Builder<EventAppCard>
     */
    public function scopePendientesDeAnular(Builder $query): Builder
    {
        return $query->whereNull('verification_voided_at')->orderBy('id');
    }

    /**
     * ¿Está vencida a día de hoy?
     *
     * LO CALCULA EL SERVIDOR Y NO LA APP, y no por comodidad: de esto depende
     * que un cobro falle, y el teléfono tiene su propio reloj y su propia
     * zona horaria —los dos cambiables por quien lo lleva—. La comparación va
     * contra el mes en curso en el huso del negocio, como todos los cortes de
     * día de la casa.
     *
     * Una tarjeta vence AL FINAL de su mes: diciembre de 2026 sigue siendo
     * buena todo diciembre. Y sin vencimiento conocido se contesta `false`:
     * no se puede afirmar que algo caducó sin saber cuándo caducaba.
     */
    public function estaVencida(): bool
    {
        if ($this->exp_month === null || $this->exp_year === null) {
            return false;
        }

        $ahora = now()->setTimezone((string) config('app.business_timezone'));

        if ($this->exp_year !== $ahora->year) {
            return $this->exp_year < $ahora->year;
        }

        return $this->exp_month < $ahora->month;
    }
}
