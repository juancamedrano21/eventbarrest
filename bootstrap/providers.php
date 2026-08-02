<?php

declare(strict_types=1);

use App\Domains\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    AdminPanelProvider::class,
];
