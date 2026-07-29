<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;

/**
 * Suma un negocio a un evento con su comisión. Idempotente: reinvitar
 * actualiza la comisión en vez de duplicar la participación.
 */
class InviteVendorToEvent
{
    public function __invoke(Event $event, Vendor $vendor, int $commissionBps = 0): EventVendor
    {
        if ($vendor->tenant_id !== $event->tenant_id) {
            throw VendorException::vendorOutsideTenant();
        }

        $participation = EventVendor::query()
            ->where('event_id', $event->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        if ($participation !== null) {
            $participation->update(['commission_bps' => $commissionBps]);

            return $participation;
        }

        $participation = new EventVendor(['commission_bps' => $commissionBps]);
        $participation->event_id = $event->id;
        $participation->vendor_id = $vendor->id;
        $participation->save();

        return $participation;
    }
}
