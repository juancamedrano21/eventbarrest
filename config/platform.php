<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Super administrador de la plataforma
    |--------------------------------------------------------------------------
    |
    | Credenciales que siembra DatabaseSeeder. Se leen desde config (no con
    | env() en runtime) para que sigan funcionando con config:cache activo.
    | Fuera de local y testing el seeder exige una contraseña propia.
    |
    */

    'superadmin' => [
        'email' => env('SUPERADMIN_EMAIL', 'admin@eventbarrest.test'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

];
