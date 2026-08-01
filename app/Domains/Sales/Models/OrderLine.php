<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea vendida, con nombre y precio INSTANTÁNEOS: el catálogo puede
 * cambiar mañana, la venta de hoy no. Inmutable tras crear.
 *
 * @property int $order_id
 * @property int $product_id
 * @property string $product_name
 * @property string $quantity
 * @property int $unit_price_cents
 * @property int $total_cents
 */
class OrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_name', 'quantity', 'unit_price_cents', 'total_cents'];

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
