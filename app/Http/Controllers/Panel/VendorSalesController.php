<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El detalle de una venta del comercio: qué se vendió, dónde, quién cobró,
 * el desglose fiscal congelado (ITBIS por línea, propina) y la comisión del
 * organizador pactada al vender. Todo es historia inmutable: esta pantalla
 * solo lee.
 */
class VendorSalesController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $vendor, int $order): View
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $record = Vendor::query()->findOrFail($vendor);

        // El tenant lo acota el scope global; el comercio, este where: una
        // venta de otro comercio (o de otra cuenta) no existe aquí.
        $sale = Order::query()
            ->where('vendor_id', $record->id)
            ->with(['lines', 'payments', 'operatingUnit.event', 'cashSession', 'user'])
            ->findOrFail($order);

        return view('panel.vendors.sale', [
            'vendor' => $record,
            'sale' => $sale,
            'payment' => $sale->payments->first(),
            'tz' => (string) config('app.business_timezone'),
        ]);
    }
}
