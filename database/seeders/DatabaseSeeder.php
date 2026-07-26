<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEV_PASSWORD = 'password';

    public function run(): void
    {
        $email = (string) config('platform.superadmin.email');
        $password = (string) (config('platform.superadmin.password') ?? '');

        if (blank($password)) {
            if (! app()->environment('local', 'testing')) {
                throw new RuntimeException(
                    'Define SUPERADMIN_PASSWORD antes de sembrar fuera de local: el super admin controla todos los negocios.'
                );
            }

            $password = self::DEV_PASSWORD;
        }

        $admin = User::query()->firstOrNew(['email' => $email]);
        $isNew = ! $admin->exists;

        $admin->forceFill([
            'name' => 'Super Admin',
            'is_platform_admin' => true,
        ]);

        // La contraseña solo se fija al crear: re-sembrar no debe revertir
        // una contraseña que alguien ya cambió.
        if ($isNew) {
            $admin->forceFill(['password' => $password]);
        }

        $admin->save();

        $this->command?->info($isNew
            ? "Super admin creado: {$email}"
            : "Super admin ya existía: {$email} (contraseña intacta)");
    }
}
