<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Models\CashSession;
use Illuminate\Support\Facades\DB;

/**
 * Saca a un comercio de un evento: deja de participar y sus puestos dejan de
 * operar.
 *
 * Los puestos se CIERRAN, no se borran, porque sus ventas los referencian y
 * una venta cobrada es historia. La comisión pactada tampoco se pierde: cada
 * orden guarda la suya congelada, así que lo ya cobrado se sigue liquidando
 * con lo que se acordó entonces.
 *
 * Con una caja abierta no se saca a nadie: el cajero se quedaría a mitad de
 * turno, sin poder cobrar y sin poder cuadrar.
 */
class RemoveVendorFromEvent
{
    public function __invoke(Event $event, Vendor $vendor): void
    {
        $participation = EventVendor::query()
            ->where('event_id', $event->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        if ($participation === null) {
            throw VendorException::vendorIsNotInTheEvent();
        }

        $puestos = EventOutlet::query()
            ->where('event_id', $event->id)
            ->where('vendor_id', $vendor->id)
            ->pluck('id');

        $cajaAbierta = CashSession::query()
            ->whereIn('operating_unit_id', $puestos)
            ->where('status', CashSessionStatus::Open->value)
            ->exists();

        if ($cajaAbierta) {
            throw VendorException::vendorHasAnOpenCashSession();
        }

        DB::transaction(function () use ($participation, $puestos): void {
            EventOutlet::query()
                ->whereIn('id', $puestos)
                ->update(['status' => OperatingUnitStatus::Closed->value]);

            $participation->delete();
        });
    }
}
