<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Actions\ProvisionTenantRoles;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Exceptions\RoleTemplateException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\Admin\Resources\RoleTemplates\Pages\CreateRoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\Pages\EditRoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\Pages\ListRoleTemplates;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * El sistema de roles de la plataforma en manos del superadmin: los roles
 * del código quedan como plantillas de sistema, se pueden crear más y
 * ajustar sus límites, y cada cambio llega a todas las cuentas.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create());
    Filament::setCurrentPanel('admin');
});

afterEach(fn () => app(TenantContext::class)->clear());

it('seeds the system templates from the code catalog', function (): void {
    Livewire::test(ListRoleTemplates::class)->assertOk();

    expect(RoleTemplate::query()->where('is_system', true)->count())->toBe(count(Role::cases()))
        ->and(RoleTemplate::query()->where('name', 'vendor_manager')->sole()->kind)->toBe(RoleKind::Vendor)
        ->and(RoleTemplate::query()->where('name', 'cashier')->sole()->kind)->toBe(RoleKind::Both);
});

it('lets the superadmin create a custom role and use it in a fresh account', function (): void {
    Livewire::test(CreateRoleTemplate::class)
        ->fillForm([
            'label' => 'Auditor',
            'description' => 'Solo mira los números.',
            'kind' => RoleKind::Account->value,
            'permissions' => [Permission::ReportsViewTenant->value, Permission::ReportsViewUnit->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $user = app(CreateTenantUser::class)($tenant, 'Aura', 'aura@bar.test', 'Secreta-2026', 'auditor');

    actAsTenantPermissions($tenant->id);
    expect($user->hasRole('auditor'))->toBeTrue()
        ->and($user->can(Permission::ReportsViewTenant->value))->toBeTrue()
        ->and($user->can(Permission::CatalogManage->value))->toBeFalse();
});

it('propagates a template edit to accounts that already existed', function (): void {
    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $user = app(CreateTenantUser::class)($tenant, 'Wally', 'wally@bar.test', 'Secreta-2026', Role::Warehouse);

    $template = RoleTemplate::query()->where('name', 'warehouse')->sole();
    $template->permissions = [Permission::InventoryManage->value];
    $template->save();

    app(ApplyRoleTemplates::class)();

    actAsTenantPermissions($tenant->id);
    expect($user->fresh()->can(Permission::InventoryTransfer->value))->toBeFalse()
        ->and($user->fresh()->can(Permission::InventoryManage->value))->toBeTrue();
});

it('applies template changes saved from the panel to every account', function (): void {
    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $user = app(CreateTenantUser::class)($tenant, 'Caro', 'caro@bar.test', 'Secreta-2026', Role::Cashier);

    $template = RoleTemplate::query()->where('name', 'cashier')->sole();

    Livewire::test(EditRoleTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm([
            'permissions' => [
                Permission::SalesOperate->value,
                Permission::CashSessionManage->value,
                Permission::SalesDiscount->value,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    actAsTenantPermissions($tenant->id);
    expect($user->fresh()->can(Permission::SalesDiscount->value))->toBeTrue();
});

it('respects the vendor boundary also for custom roles', function (): void {
    Livewire::test(CreateRoleTemplate::class)
        ->fillForm([
            'label' => 'Runner de puesto',
            'kind' => RoleKind::Vendor->value,
            'permissions' => [Permission::SalesOperate->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $vendor = app(TenantContext::class)->runAs($organizer, fn () => app(CreateVendor::class)('Tacos'));

    // A personal de comercio: bien.
    $runner = app(CreateTenantUser::class)($organizer, 'Rita', 'rita@x.test', 'Secreta-2026', 'runner_de_puesto', $vendor);
    expect($runner->vendor_id)->toBe($vendor->id);

    // Al equipo de la cuenta: rechazado.
    app(CreateTenantUser::class)($organizer, 'Ana', 'ana@x.test', 'Secreta-2026', 'runner_de_puesto');
})->throws(VendorException::class);

it('never edits the owner template', function (): void {
    Livewire::test(ListRoleTemplates::class)->assertOk();
    $owner = RoleTemplate::query()->where('name', 'owner')->sole();

    $owner->permissions = [Permission::UsersManage->value];
    $owner->save();
})->throws(RoleTemplateException::class);

it('never deletes a system template', function (): void {
    Livewire::test(ListRoleTemplates::class)->assertOk();

    RoleTemplate::query()->where('name', 'cashier')->sole()->delete();
})->throws(RoleTemplateException::class);

it('refuses to delete a custom role in use, and cleans up an unused one', function (): void {
    Livewire::test(CreateRoleTemplate::class)
        ->fillForm([
            'label' => 'Auditor',
            'kind' => RoleKind::Account->value,
            'permissions' => [Permission::ReportsViewTenant->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $user = app(CreateTenantUser::class)($tenant, 'Aura', 'aura@bar.test', 'Secreta-2026', 'auditor');

    $template = RoleTemplate::query()->where('name', 'auditor')->sole();

    expect(fn () => $template->delete())->toThrow(RoleTemplateException::class);

    // Sin usuarios, se elimina y arrastra sus filas por cuenta.
    $user->delete();
    $template->fresh()->delete();

    expect(SpatieRole::query()->where('name', 'auditor')->exists())->toBeFalse();
});

it('rejects permissions that no code checks', function (): void {
    $template = new RoleTemplate([
        'label' => 'Fantasma',
        'permissions' => ['dinero.imprimir'],
    ]);
    $template->save();
})->throws(RoleTemplateException::class);

it('keeps regular platform users out of the admin panel screens', function (): void {
    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $owner = app(CreateTenantUser::class)($tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);

    expect($this->actingAs($owner)->get('/saas-admin/role-templates')->getStatusCode())->toBe(403);
});

// ── Correcciones de la revisión adversarial ──────────────────────────────

it('reserves the system identifiers even on a virgin platform', function (): void {
    // Sin siembra previa: crear «Owner» capturaría el identificador del rol
    // de dueño y ninguna cuenta podría tener dueño jamás.
    $template = new RoleTemplate([
        'label' => 'Owner',
        'permissions' => [Permission::SalesOperate->value],
    ]);

    $template->save();
})->throws(RoleTemplateException::class);

it('rejects a label that cannot produce an identifier', function (): void {
    $template = new RoleTemplate([
        'label' => '###',
        'permissions' => [Permission::SalesOperate->value],
    ]);

    $template->save();
})->throws(RoleTemplateException::class);

it('keeps account-only permissions out of vendor-assignable roles', function (): void {
    // La frontera cuenta/comercio no se cruza ni componiendo roles: un rol
    // asignable a personal de comercio no puede llevar administración de
    // cuenta (equipo, eventos, comercios, fiscal...).
    $template = new RoleTemplate([
        'label' => 'Runner con equipo',
        'permissions' => [Permission::SalesOperate->value, Permission::UsersManage->value],
    ]);
    $template->forceFill(['kind' => RoleKind::Vendor->value])->save();
})->throws(RoleTemplateException::class);

it('never lets anyone grant a role above their own ceiling', function (): void {
    Livewire::test(CreateRoleTemplate::class)
        ->fillForm([
            'label' => 'Coordinador',
            'kind' => RoleKind::Account->value,
            'permissions' => [Permission::UsersManage->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $coordinador = app(CreateTenantUser::class)($tenant, 'Coco', 'coco@bar.test', 'Secreta-2026', 'coordinador');

    // Con solo users.manage intenta auto-ascenderse a dueño: denegado.
    app(AssignTenantRole::class)($coordinador, Role::Owner, $coordinador);
})->throws(RoleTemplateException::class);

it('keeps a cashier clone with another name out of the management panel', function (): void {
    // El candado del POS se decide por capacidades, no por el nombre del rol.
    Livewire::test(CreateRoleTemplate::class)
        ->fillForm([
            'label' => 'Barra',
            'kind' => RoleKind::Account->value,
            'permissions' => [Permission::SalesOperate->value, Permission::CashSessionManage->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $barra = app(CreateTenantUser::class)($tenant, 'Bea', 'bea@bar.test', 'Secreta-2026', 'barra');

    // No entra al panel de gestión, y como su trabajo entero es la caja se
    // le manda allí en vez de darle un 403 sin salida.
    $this->actingAs($barra)->get('/business')->assertRedirect('/pos');
});

it('re-seeds a missing system template instead of giving up forever', function (): void {
    Livewire::test(ListRoleTemplates::class)->assertOk();

    // Simula una siembra interrumpida (SQL directo: los guards no corren).
    DB::table('role_templates')->where('name', 'cashier')->delete();

    RoleTemplate::ensureSystemTemplates();

    expect(RoleTemplate::query()->where('name', 'cashier')->exists())->toBeTrue();
});

it('cleans up orphan spatie roles without holders and keeps the ones in use', function (): void {
    $tenant = app(CreateTenant::class)('Bar del Puerto');
    $user = app(CreateTenantUser::class)($tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);

    // Dos huérfanos sin plantilla: uno sin titulares y uno asignado.
    actAsTenantPermissions($tenant->id);
    $suelto = SpatieRole::create(['name' => 'legacy_libre', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $vivo = SpatieRole::create(['name' => 'legacy_usado', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $user->assignRole($vivo);

    app(ProvisionTenantRoles::class)($tenant);

    expect(SpatieRole::query()->where('name', 'legacy_libre')->exists())->toBeFalse()
        ->and(SpatieRole::query()->where('name', 'legacy_usado')->exists())->toBeTrue();
});
