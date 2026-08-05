<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Platform\Enums\TenantStatus;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * La única pieza que decide el contexto de un usuario autenticado: cuenta,
 * equipo de permisos y comercio. La consumen el middleware y el helper de
 * tests por igual, para que no puedan divergir.
 *
 * Empieza siempre limpiando: fuera de Octane el contenedor puede conservar
 * el contexto de una petición anterior (tests, colas), y heredarlo sería
 * operar como otra cuenta.
 */
class ContextResolver
{
    public function forUser(?User $user): void
    {
        app(TenantContext::class)->clear();
        app(VendorContext::class)->clear();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        app(TenantContext::class)->set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // La relación roles() se filtra por el equipo VIGENTE cuando se
        // carga. Si algo la tocó antes (autenticación, un observer), quedó
        // cacheada con equipo nulo — vacía — y el usuario perdería sus
        // permisos el resto de la petición. Se descarta para recargarla ya
        // con el equipo correcto.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        if ($user->vendor_id === null) {
            return;
        }

        // Con el tenant fijado, el scope de Vendor garantiza que solo se
        // encuentre un comercio de ESTA cuenta. Si no aparece o está
        // suspendido, se niega la petición: continuar sin comercio activo
        // fallaría ABIERTO — el usuario vería el consolidado de la cuenta.
        $vendor = Vendor::query()->find($user->vendor_id);

        if ($vendor === null || $vendor->status === VendorStatus::Suspended) {
            abort(403, 'El comercio de este usuario no está disponible.');
        }

        app(VendorContext::class)->set($vendor);
    }

    /**
     * El contexto de un DISPOSITIVO: la tablet clavada en la ventanilla, que
     * entra sin ser nadie.
     *
     * Vive aquí, pegado a forUser(), porque el docblock de arriba no es
     * decorativo: si la tablet decidiera su contexto en su propio middleware
     * habría dos sitios donde vive la regla de «qué cuenta y qué comercio
     * operan», y el día que cambie uno de los dos se quedará atrás. Que sea
     * el mismo archivo obliga a leer las dos reglas juntas.
     */
    public function forDevice(KdsDevice $device): void
    {
        app(TenantContext::class)->clear();
        app(VendorContext::class)->clear();

        // Y el equipo de permisos se queda en NULO a propósito, que es justo
        // lo contrario de lo que hace forUser(). Un dispositivo no es una
        // persona: no tiene roles y no puede tenerlos. Dejándolo nulo,
        // cualquier ->can() que se cuele por descuido en un controlador del
        // KDS devuelve false en vez de heredar los permisos del último
        // humano que pasó por este contenedor. Fail-closed por construcción,
        // sin depender de que nadie se acuerde de comprobarlo.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $tenant = $device->tenant()->first();

        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        app(TenantContext::class)->set($tenant);

        // Con la cuenta puesta, el scope de Vendor garantiza que solo se
        // encuentre un comercio de ESTA cuenta.
        $vendor = Vendor::query()->find($device->vendor_id);

        if ($vendor === null || $vendor->status !== VendorStatus::Active) {
            // Sin comercio no hay KDS, así que se deshace también la cuenta:
            // un contexto a medias es el peor de los tres estados posibles,
            // porque VendorScope falla ABIERTO y la tablet leería el
            // consolidado del evento entero —las comandas de su competidor
            // incluidas— sin que nada reventara. Quien llama vuelve a
            // comprobarlo con VendorContext::check(), y así debe ser.
            app(TenantContext::class)->clear();

            return;
        }

        app(VendorContext::class)->set($vendor);
    }

    /**
     * El contexto de un EVENTO, resuelto del código que viene en la URL: la
     * puerta pública de la app del asistente, donde quien llama no es ni una
     * persona ni un aparato enrolado, sino un teléfono anónimo que solo sabe
     * a qué festival pertenece.
     *
     * Vive aquí, con las otras dos, por lo mismo que forDevice(): la regla de
     * «qué cuenta opera» no puede tener tres sitios donde vivir.
     *
     * Sin esto la puerta sería un 200 mentiroso. No hay sesión ni token, así
     * que el contenedor llega limpio, TenantScope falla CERRADO y emite
     * `where 1 = 0`: el manifiesto saldría con la marca de fábrica y la lista
     * de comercios VACÍA, con 200 y sin una sola excepción. Un festival lleno
     * de teléfonos enseñando «este evento no tiene comercios» mientras el
     * servidor jura que todo va bien.
     *
     * NO fija comercio, y es deliberado. El manifiesto y la lista de puestos
     * son del evento entero, así que la vista consolidada de la cuenta —que
     * es lo que da VendorScope sin comercio en contexto— es exactamente la
     * correcta, y TenantScope ya impide que se cuele otra cuenta. El comercio
     * lo fija la carta, que es el único endpoint que habla de uno solo, y ahí
     * el fail-open de VendorScope se tapa con su backstop explícito.
     */
    public function forEvent(Event $event): void
    {
        app(TenantContext::class)->clear();
        app(VendorContext::class)->clear();

        // Nulo igual que en forDevice y por el mismo motivo: un teléfono
        // anónimo no tiene roles, así que cualquier ->can() que se cuele en
        // un controlador de esta puerta devuelve false en vez de heredar los
        // permisos del último humano que pasó por este contenedor.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $tenant = $event->tenant()->first();

        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        app(TenantContext::class)->set($tenant);
    }
}
