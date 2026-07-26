<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds the platform super admin', function (): void {
    config(['platform.superadmin' => ['email' => 'jefe@ejemplo.test', 'password' => 'secreto-largo']]);

    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'jefe@ejemplo.test')->sole();

    expect($admin->is_platform_admin)->toBeTrue()
        ->and(Hash::check('secreto-largo', $admin->password))->toBeTrue();
});

it('never resets an existing password when re-seeded', function (): void {
    config(['platform.superadmin' => ['email' => 'jefe@ejemplo.test', 'password' => 'inicial-larga']]);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'jefe@ejemplo.test')->sole();
    $admin->forceFill(['password' => 'cambiada-por-el-humano'])->save();

    $this->seed(DatabaseSeeder::class);

    expect(Hash::check('cambiada-por-el-humano', $admin->fresh()->password))->toBeTrue()
        ->and(User::query()->where('email', 'jefe@ejemplo.test')->count())->toBe(1);
});

it('refuses to seed a default password outside local', function (): void {
    config(['platform.superadmin' => ['email' => 'jefe@ejemplo.test', 'password' => null]]);
    $this->app['env'] = 'production';

    expect(fn () => (new DatabaseSeeder)->run())
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'jefe@ejemplo.test')->exists())->toBeFalse();
});
