<?php

declare(strict_types=1);

use App\Models\User;

it('redirects guests to the panel login', function (string $panel): void {
    $this->get("/{$panel}")->assertRedirect("/{$panel}/login");
})->with(['admin', 'app']);

it('forbids regular users on both panels until Identity lands tenant roles', function (string $panel): void {
    $this->actingAs(User::factory()->create());

    $this->get("/{$panel}")->assertForbidden();
})->with(['admin', 'app']);

it('lets platform admins into both panels', function (string $panel): void {
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get("/{$panel}")->assertOk();
})->with(['admin', 'app']);
