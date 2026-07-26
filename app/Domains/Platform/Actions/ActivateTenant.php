<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;

class ActivateTenant
{
    public function __invoke(Tenant $tenant): Tenant
    {
        $tenant->update(['status' => TenantStatus::Active]);

        return $tenant;
    }
}
