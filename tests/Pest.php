<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshesDatabaseWithFixtures;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshesDatabaseWithFixtures::class)
    ->in('Feature', 'TenantIsolation');

/**
 * spatie/permission en modo teams resuelve los roles contra el equipo activo.
 * Fuera de una petición HTTP (donde lo fija SetTenantContext) hay que decirlo
 * explícitamente, y limpiar la caché para que el cambio se note en el acto.
 */
function actAsTenantPermissions(?int $tenantId): void
{
    setPermissionsTeamId($tenantId);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Un punto de venta necesita ahora un negocio invitado al evento: los
 * negocios son quienes venden dentro de un festival. El helper crea el
 * negocio, lo invita y devuelve el punto listo.
 */
function outletFor(
    Event $event,
    string $name,
    OperatingUnitKind $kind,
    ?Vendor $vendor = null,
): EventOutlet {
    $vendor ??= vendorIn($event);

    return app(CreateEventOutlet::class)($event, $vendor, $name, $kind);
}

/** Da de alta un negocio y lo invita al evento. */
function vendorIn(
    Event $event,
    ?string $name = null,
): Vendor {
    $vendor = app(CreateVendor::class)(
        $name ?? 'Negocio '.Str::random(6)
    );

    app(InviteVendorToEvent::class)($event, $vendor);

    return $vendor;
}

/**
 * Livewire::test monta el componente sin pasar por el middleware, así que el
 * contexto de tenant se fija a mano — igual que haría SetTenantContext en una
 * petición real. Sin él, el aislamiento falla cerrado y no se ve ningún dato.
 */
function signInTo(object $test, $user, $tenant): void
{
    $test->actingAs($user);
    app(TenantContext::class)->set($tenant);
    actAsTenantPermissions($tenant->id);

    // Espejo del middleware: el personal de un comercio opera siempre con
    // su comercio activo; el resto ve el consolidado de la cuenta.
    $vendors = app(VendorContext::class);
    $vendors->clear();

    if ($user->vendor_id !== null) {
        $vendor = Vendor::query()->find($user->vendor_id);

        if ($vendor !== null) {
            $vendors->set($vendor);
        }
    }

    Filament::setCurrentPanel('app');
}
