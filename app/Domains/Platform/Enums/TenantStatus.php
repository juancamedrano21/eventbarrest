<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
}
