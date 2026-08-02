<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Exceptions\RoleTemplateException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Identity\Queries\TenantOwners;
use App\Domains\Identity\Queries\UserPermissions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * El equipo de la CUENTA del organizador: quien administra eventos,
 * comercios y reportes. No el personal de los comercios — ese se gestiona
 * desde el perfil de cada uno, porque su comercio es parte de quién es.
 *
 * Esta pantalla la tenía el panel Filament que se retiró y no quedó en
 * ninguna parte: sin ella, un organizador no podía dar de alta a su propio
 * equipo.
 */
class TeamController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function index(Request $request): View
    {
        $tenant = $this->authorizeOrganizer($request, Permission::UsersManage);

        // Solo el equipo de la cuenta: quien pertenece a un comercio se
        // administra en el perfil de su comercio.
        $equipo = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('vendor_id')
            ->where('is_platform_admin', false)
            ->orderBy('name')
            ->get();

        $roles = RoleTemplate::optionsForAccountStaff();
        $rolDe = app(UserPermissions::class)
            ->roleNamesFor((int) $tenant->id, $equipo->pluck('id')->all());

        $duenos = app(TenantOwners::class)->count((int) $tenant->id);

        return view('event-panel.team', [
            'equipo' => $equipo->map(fn (User $u): array => [
                'user' => $u,
                'rolNombre' => $rolDe[$u->id] ?? null,
                'rol' => $roles[$rolDe[$u->id] ?? ''] ?? RoleTemplate::labelFor((string) ($rolDe[$u->id] ?? '')) ?? '—',
                // Su rol vigente va en su propio desplegable aunque ya no se
                // ofrezca: sin opción marcada, el navegador enviaría la
                // primera de la lista y guardar ascendería en silencio.
                'roles' => $this->rolesPara($roles, $rolDe[$u->id] ?? null),
                'esUltimoDueno' => ($rolDe[$u->id] ?? null) === Role::Owner->value && $duenos <= 1,
                'esUnoMismo' => $u->id === $request->user()?->id,
            ]),
            'roles' => $roles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->authorizeOrganizer($request, Permission::UsersManage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_keys(RoleTemplate::optionsForAccountStaff()))],
        ], [
            'username.regex' => 'El usuario del POS solo admite letras, números, punto, guion y guion bajo.',
        ], ['name' => 'nombre', 'password' => 'contraseña', 'role' => 'rol']);

        try {
            app(CreateTenantUser::class)(
                $tenant,
                $data['name'],
                $data['email'],
                $data['password'],
                $data['role'],
                null,
                $request->user(),
                $data['username'] ?? null,
            );
        } catch (RoleTemplateException|VendorException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Usuario creado.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $tenant = $this->authorizeOrganizer($request, Permission::UsersManage);
        $target = $this->delEquipo($tenant->id, $user);

        $rolVigente = app(UserPermissions::class)
            ->roleNamesFor((int) $tenant->id, [$target->id])[$target->id] ?? null;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i',
                Rule::unique('users', 'username')->ignore($target->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_keys(
                $this->rolesPara(RoleTemplate::optionsForAccountStaff(), $rolVigente),
            ))],
        ], [
            'username.regex' => 'El usuario del POS solo admite letras, números, punto, guion y guion bajo.',
        ], ['name' => 'nombre', 'password' => 'contraseña', 'role' => 'rol']);

        try {
            app(AssignTenantRole::class)($target, $data['role'], $request->user());
        } catch (LastOwnerException|RoleTemplateException|VendorException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        $target->name = $data['name'];
        $target->email = $data['email'];
        $target->username = $data['username'] ?? null;

        if (filled($data['password'] ?? null)) {
            $target->password = Hash::make($data['password']);
        }

        $target->save();

        return back()->with('status', 'Usuario actualizado.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $tenant = $this->authorizeOrganizer($request, Permission::UsersManage);
        $target = $this->delEquipo($tenant->id, $user);

        if ($target->id === $request->user()?->id) {
            return back()->withErrors(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        if (app(TenantOwners::class)->isLastOwner($target)) {
            return back()->withErrors([
                'user' => 'Es la única cuenta de dueño: nombra otro dueño antes de eliminarla.',
            ]);
        }

        $target->delete();

        return back()->with('status', 'Usuario eliminado.');
    }

    /**
     * @param  array<string, string>  $ofrecidos
     * @return array<string, string>
     */
    private function rolesPara(array $ofrecidos, ?string $vigente): array
    {
        if ($vigente === null || array_key_exists($vigente, $ofrecidos)) {
            return $ofrecidos;
        }

        return [$vigente => RoleTemplate::labelFor($vigente) ?? $vigente] + $ofrecidos;
    }

    /**
     * Alguien del equipo de ESTA cuenta y sin comercio. `User` no lleva scope
     * de cuenta —el login ocurre antes de que haya una—, así que la frontera
     * se pone a mano.
     */
    private function delEquipo(int $tenantId, int $user): User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('vendor_id')
            ->where('is_platform_admin', false)
            ->findOrFail($user);
    }
}
