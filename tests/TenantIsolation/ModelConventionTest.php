<?php

declare(strict_types=1);

use App\Domains\Business\Models\BusinessAccount;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppLoginCode;
use App\Domains\EventApp\Models\EventAppSession;
use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Platform\Models\FoodType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Platform\Models\VendorType;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Symfony\Component\Finder\Finder;

/**
 * A single business model that forgets the trait exposes its whole table.
 * "Enforced in code review" is not enforcement — this is.
 */
$platformModels = [
    Tenant::class,
    BusinessAccount::class,
    OrganizerAccount::class,
    // Catálogo de roles de la plataforma: lo administra el superadmin y se
    // materializa por cuenta vía spatie; la plantilla en sí no tiene tenant.
    RoleTemplate::class,
    VendorType::class,
    FoodType::class,
    // La cuenta del asistente y sus satélites son de PLATAFORMA a
    // conciencia: el primer actor que no es el superadmin y vive fuera de
    // toda cuenta de negocio — la identidad que ata boleta, pulsera y
    // monedero a través de eventos de organizadores distintos. Un tenant_id
    // la partiría en una identidad por organizador. Las tablas están
    // exceptuadas con el mismo argumento en SchemaConventionTest. ADR-011.
    EventAppAccount::class,
    EventAppSession::class,
    EventAppLoginCode::class,
];

it('makes every domain model tenant-scoped', function () use ($platformModels): void {
    $modelsPath = app_path('Domains');

    if (! is_dir($modelsPath)) {
        expect(true)->toBeTrue();

        return;
    }

    $files = Finder::create()->files()->in($modelsPath)->path('/Models/')->name('*.php');

    foreach ($files as $file) {
        $class = 'App\\Domains\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $file->getRelativePathname()
        );

        if (! class_exists($class) || in_array($class, $platformModels, true)) {
            continue;
        }

        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))->toBeTrue(
            "Model [{$class}] must use the BelongsToTenant trait, or be listed as a platform-level ".
            'model in this test with a reason.'
        );
    }
});
