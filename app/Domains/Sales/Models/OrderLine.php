<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Models\Product;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Eloquent\SalesHistoryBuilder;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * Una línea vendida, con nombre, precio y desglose de ITBIS INSTANTÁNEOS:
 * el catálogo puede cambiar mañana, la venta de hoy no. itbis_cents es cero
 * cuando el producto era exento al vender. Inmutable tras crear.
 *
 * `dispatch` también se congela: de qué área sale esto vive en la categoría,
 * que es mutable, y recategorizar un producto no debe reescribir qué
 * comandas fueron de cocina el mes pasado. Puede ser null en líneas
 * anteriores a la columna cuyo producto ya no existe.
 *
 * `notes` es lo único de aquí que no es un hecho económico: es lo que hay
 * que leer antes de cocinar («sin cebolla»), y viaja con la línea porque
 * es de ESE plato, no del pedido entero.
 *
 * @property int $order_id
 * @property int $product_id
 * @property string $product_name
 * @property DispatchArea|null $dispatch
 * @property string|null $notes
 * @property string $quantity
 * @property int $unit_price_cents
 * @property int $total_cents
 * @property int $itbis_cents
 */
class OrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_name', 'dispatch', 'notes', 'quantity', 'unit_price_cents', 'total_cents', 'itbis_cents'];

    protected function casts(): array
    {
        return [
            'dispatch' => DispatchArea::class,
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'itbis_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // La frontera de comercios también en la venta: el producto de la
        // línea debe ser del MISMO comercio que la unidad de la orden.
        // Consultas sin scopes: la verdad de las filas, no la vista.
        static::creating(function (OrderLine $line): void {
            if ($line->getAttribute('tenant_id') === null) {
                return;
            }

            $order = Order::query()->withoutGlobalScopes()->find($line->order_id);
            $product = Product::query()->withoutGlobalScopes()
                ->whereKey($line->product_id)
                ->first(['tenant_id', 'vendor_id']);

            if ($order === null || $product === null
                || $order->getAttribute('tenant_id') !== $line->getAttribute('tenant_id')
                || $product->getAttribute('tenant_id') !== $line->getAttribute('tenant_id')) {
                throw SalesException::unitOutsideTenant();
            }

            $unitVendor = OperatingUnit::query()->withoutGlobalScopes()
                ->whereKey($order->operating_unit_id)
                ->value('vendor_id');

            if ($product->getAttribute('vendor_id') !== $unitVendor) {
                throw SalesException::lineOutsideOrderVendor();
            }
        });

        static::updating(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });

        static::deleting(function (): void {
            throw SalesException::paidOrdersAreHistory();
        });
    }

    /**
     * @param  Builder  $query
     * @return SalesHistoryBuilder<*>
     */
    public function newEloquentBuilder($query): SalesHistoryBuilder
    {
        return new SalesHistoryBuilder($query);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
