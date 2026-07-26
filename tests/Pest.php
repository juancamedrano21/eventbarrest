<?php

declare(strict_types=1);

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
