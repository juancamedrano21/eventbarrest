<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventVendor;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventVendor\Concerns\AuthorizesEventVendorPanel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El detalle de una venta desde la puerta del encargado: la misma vista
 * inmutable que ve el organizador, acotada a SU comercio.
 */
class SalesController extends Controller
{
    use AuthorizesEventVendorPanel;

    public function show(Request $request, int $order): View
    {
        $record = $this->comercioDe($request, Permission::ReportsViewUnit);

        $sale = Order::query()
            ->where('vendor_id', $record->id)
            ->with(['lines', 'payments', 'refunds.user', 'operatingUnit.event', 'cashSession', 'user'])
            ->findOrFail($order);

        return view('event-panel.vendors.sale', [
            'vendor' => $record,
            'sale' => $sale,
            'payment' => $sale->payments->first(),
            'tz' => (string) config('app.business_timezone'),
            'volver' => route('event-vendor.home'),
            // El chrome de SU puerta, no el del organizador.
            'layoutVenta' => 'event-vendor.layout',
        ]);
    }
}
