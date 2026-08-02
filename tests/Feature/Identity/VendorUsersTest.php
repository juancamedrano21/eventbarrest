<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * Los usuarios de comercio: el dueño del evento los crea desde su panel y
 * los asigna a un comercio; desde entonces ese usuario opera únicamente
 * dentro de su comercio, con roles de comercio — nunca de cuenta.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    $this->vendor = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(CreateVendor::class)('Tacos del Puerto'),
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('creates a user attached to a vendor of the account', function (): void {
    $user = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    expect($user->vendor_id)->toBe($this->vendor->id)
        ->and($user->tenant_id)->toBe($this->organizer->id);
});

it('rejects a vendor from another account', function (): void {
    $otro = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);
    $ajeno = app(TenantContext::class)->runAs($otro, fn () => app(CreateVendor::class)('Ajeno'));

    app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::VendorManager, $ajeno,
    );
})->throws(VendorException::class);

it('refuses account roles for vendor staff', function (): void {
    app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::Owner, $this->vendor,
    );
})->throws(VendorException::class);

it('refuses the vendor manager role outside a vendor', function (): void {
    app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::VendorManager,
    );
})->throws(VendorException::class);

it('keeps vendor staff from being promoted to an account role later', function (): void {
    $user = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::Cashier, $this->vendor,
    );

    app(AssignTenantRole::class)($user, Role::Admin);
})->throws(VendorException::class);

it('allows moving vendor staff between vendor roles', function (): void {
    $user = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::Cashier, $this->vendor,
    );

    app(AssignTenantRole::class)($user, Role::VendorManager);

    actAsTenantPermissions($this->organizer->id);
    expect($user->fresh()->hasRole(Role::VendorManager->value))->toBeTrue();
});

it('never lets platform staff belong to a vendor', function (): void {
    $staff = new User;
    $staff->forceFill([
        'name' => 'Staff',
        'email' => 'staff@plataforma.test',
        'password' => 'Secreta-2026',
        'is_platform_admin' => true,
        'vendor_id' => $this->vendor->id,
        'email_verified_at' => now(),
    ])->save();
})->throws(VendorException::class);

it('blocks panel access for staff of a suspended vendor', function (): void {
    $user = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $this->vendor->update(['status' => VendorStatus::Suspended]),
    );

    expect($this->actingAs($user)->get('/event-vendor')->getStatusCode())->toBe(403);
});

it('refuses to delete a vendor that still has users', function (): void {
    app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@tacos.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    app(TenantContext::class)->runAs($this->organizer, fn () => $this->vendor->delete());
})->throws(VendorException::class);
