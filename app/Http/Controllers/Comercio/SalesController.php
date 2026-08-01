<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comercio;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Comercio\Concerns\AuthorizesComercioPanel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El detalle de una venta desde la puerta del encargado: la misma vista
 * inmutable que ve el organizador, acotada a SU comercio.
 */
class SalesController extends Controller
{
    use AuthorizesComercioPanel;

    public function show(Request $request, int $order): View
    {
        $record = $this->comercioDe($request, Permission::ReportsViewUnit);

        $sale = Order::query()
            ->where('vendor_id', $record->id)
            ->with(['lines', 'payments', 'operatingUnit.event', 'cashSession', 'user'])
            ->findOrFail($order);

        return view('panel.vendors.sale', [
            'vendor' => $record,
            'sale' => $sale,
            'payment' => $sale->payments->first(),
            'tz' => (string) config('app.business_timezone'),
            'volver' => route('comercio.home'),
        ]);
    }
}
