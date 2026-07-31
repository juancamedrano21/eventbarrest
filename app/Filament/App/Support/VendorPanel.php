<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Tenancy\TenantContext;

/**
 * Las dos preguntas que el panel se hace en el mundo de eventos:
 *
 * - ¿Se puede operar? En una cuenta de organizador el catálogo y el
 *   inventario pertenecen a los comercios: solo se escriben con un comercio
 *   activo (usuario de comercio). El equipo del organizador mira, no opera.
 *   En una cuenta de negocio no hay comercios y se opera como siempre.
 * - ¿Es la vista consolidada del organizador? Ahí las pantallas muestran de
 *   quién es cada fila (columna y filtro de comercio).
 */
class VendorPanel
{
    public static function writesAllowed(): bool
    {
        $tenant = app(TenantContext::class)->current();

        // Sin contexto de cuenta no se escribe: igual de fail-closed que
        // TenantScope. En el panel el middleware siempre lo fija, así que
        // esto solo niega estados anómalos (jobs o código sin contexto).
        if ($tenant === null) {
            return false;
        }

        return ! $tenant instanceof OrganizerAccount
            || app(VendorContext::class)->check();
    }

    public static function consolidatedOrganizerView(): bool
    {
        return app(TenantContext::class)->current() instanceof OrganizerAccount
            && ! app(VendorContext::class)->check();
    }
}
