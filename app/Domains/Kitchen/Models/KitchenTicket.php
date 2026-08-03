<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Models;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La comanda: una venta vista desde la ventanilla que la despacha.
 *
 * Es MUTABLE a conciencia, y eso la separa de todas sus vecinas. `Order` y
 * `OrderLine` son historia congelada; `Refund` nace y ya no se toca. Esta
 * fila, en cambio, existe justamente para cambiar: se mueve entre tres
 * estados mientras se cocina, y se puede volver atrás porque los toques
 * equivocados en una pantalla grasienta son parte del oficio. Por eso la
 * comanda es un hecho NUEVO al lado de la orden y no una columna dentro de
 * ella: la venta sigue siendo intocable aunque el plato vaya y venga.
 *
 * Lo mutable es el ESTADO y nada más. Qué se despacha, por dónde y cuánto
 * nace con la fila y ahí se queda, y el estado solo entra por saveTransition().
 *
 * @property int $operating_unit_id
 * @property int $order_id
 * @property DispatchArea $area
 * @property KitchenTicketStatus $status
 * @property int $items_count
 * @property Carbon|null $started_at
 * @property Carbon|null $ready_at
 * @property int|null $started_by_device_id
 * @property int|null $ready_by_device_id
 * @property-read Order|null $order
 * @property-read OperatingUnit|null $unit
 */
class KitchenTicket extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    protected $fillable = [
        'operating_unit_id',
        'order_id',
        'area',
        'items_count',
        'started_at',
        'ready_at',
        'started_by_device_id',
        'ready_by_device_id',
    ];

    /** Solo saveTransition() la enciende: es la única puerta del estado. */
    private bool $transitionWrite = false;

    protected static function booted(): void
    {
        // El estado es lo único que se mueve, y se mueve por su puerta.
        // Vale también al crear: nacer «en proceso» ES la transición desde
        // pendiente, porque pendiente es la ausencia de esta fila.
        $guardaEstado = function (KitchenTicket $ticket): void {
            if ($ticket->isDirty('status') && ! $ticket->transitionWrite) {
                throw KitchenException::estadoSoloPorTransicion();
            }
        };

        static::creating($guardaEstado);

        static::updating(function (KitchenTicket $ticket) use ($guardaEstado): void {
            $guardaEstado($ticket);

            // Cambiar cualquiera de estas cuatro no es corregir la comanda,
            // es fabricar otra distinta encima del rastro de la primera.
            foreach (['order_id', 'area', 'operating_unit_id', 'items_count'] as $columna) {
                if ($ticket->isDirty($columna)) {
                    throw KitchenException::identidadInmutable();
                }
            }
        });

        // Ni siquiera para «limpiar el tablero»: lo abierto se cierra
        // marcándolo listo, y lo listo es el rastro de quién cocinó qué.
        static::deleting(function (): void {
            throw KitchenException::comandaNoSeBorra();
        });
    }

    /**
     * La única puerta por la que se escribe el estado. El llamador coloca el
     * destino y los sellos que lo acompañan (started_at, ready_by_device_id…)
     * y esto lo persiste tras comprobar la matriz.
     *
     * Un modelo recién creado viene sin estado original: su origen es
     * Pendiente, que es exactamente lo que significa que la fila no existiera.
     */
    public function saveTransition(): void
    {
        if ($this->isDirty('status')) {
            $original = $this->getOriginal('status');
            $desde = $original instanceof KitchenTicketStatus
                ? $original
                : KitchenTicketStatus::Pending;

            if (! $desde->canTransitionTo($this->status)) {
                throw KitchenException::transicionImposible($desde, $this->status);
            }
        }

        $this->transitionWrite = true;

        try {
            $this->save();
        } finally {
            $this->transitionWrite = false;
        }
    }

    protected function casts(): array
    {
        return [
            'area' => DispatchArea::class,
            'status' => KitchenTicketStatus::class,
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'items_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OperatingUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'operating_unit_id');
    }
}
