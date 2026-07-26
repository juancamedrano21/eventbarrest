<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Actions\ProvisionTenantRoles;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Aprovisiona roles y permisos de los negocios ya existentes. Necesario tras
 * añadir un permiso o un rol nuevo al catálogo: los negocios dados de alta
 * antes no lo tendrían.
 */
class ProvisionRolesCommand extends Command
{
    protected $signature = 'identity:provision-roles {--tenant= : Solo este negocio, por id}';

    protected $description = 'Crea o actualiza los roles y permisos de cada negocio';

    public function handle(ProvisionTenantRoles $provision): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay negocios que aprovisionar.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $provision($tenant);
            $this->line("  Roles listos para [{$tenant->name}]");
        }

        $this->info("Aprovisionados {$tenants->count()} negocio(s).");

        return self::SUCCESS;
    }
}
