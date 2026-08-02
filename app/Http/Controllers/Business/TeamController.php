<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Identity\Queries\TenantOwners;
use App\Domains\Identity\Queries\UserPermissions;
use App\Http\Controllers\Business\Concerns\AuthorizesBusinessPanel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * El equipo del negocio: quién entra y con qué rol.
 *
 * Dos correos y un usuario corto: el equipo entra por correo y quien está en
 * la caja por un usuario sin arroba, que es lo que se teclea rápido en una
 * tableta. Los dos caminos llevan al mismo login.
 *
 * La última cuenta de dueño no se puede degradar ni borrar: dejaría el
 * negocio sin nadie que pueda dar de alta a nadie.
 */
class TeamController extends Controller
{
    use AuthorizesBusinessPanel;

    public function index(Request $request): View
    {
        $negocio = $this->negocioDe($request, Permission::UsersManage->value);

        $equipo = User::query()
            ->where('tenant_id', $negocio->id)
            ->where('is_platform_admin', false)
            ->orderBy('name')
            ->get();

        $roles = RoleTemplate::optionsForBusinessStaff();
        $rolDe = app(UserPermissions::class)
            ->roleNamesFor((int) $negocio->id, $equipo->pluck('id')->all());

        $duenos = app(TenantOwners::class)->count((int) $negocio->id);

        return view('business.team', [
            'equipo' => $equipo->map(fn (User $u): array => [
                'user' => $u,
                'rolNombre' => $rolDe[$u->id] ?? null,
                'rol' => $roles[$rolDe[$u->id] ?? ''] ?? RoleTemplate::labelFor((string) ($rolDe[$u->id] ?? '')) ?? '—',
                // El último dueño no se degrada ni se borra: dejaría la
                // cuenta sin nadie que pueda dar de alta a nadie.
                'esUltimoDueno' => ($rolDe[$u->id] ?? null) === Role::Owner->value && $duenos <= 1,
                'esUnoMismo' => $u->id === $request->user()?->id,
            ]),
            'roles' => $roles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::UsersManage->value);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_keys(RoleTemplate::optionsForBusinessStaff()))],
        ], [
            'username.regex' => 'El usuario del POS solo admite letras, números, punto, guion y guion bajo.',
        ], ['name' => 'nombre', 'password' => 'contraseña', 'role' => 'rol']);

        // El actor va para que aplique el techo antiescalada: nadie concede
        // un rol con más permisos de los que tiene.
        app(CreateTenantUser::class)(
            $negocio,
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role'],
            null,
            $this->actor(),
            $data['username'] ?? null,
        );

        return back()->with('status', 'Usuario creado.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::UsersManage->value);

        $user = User::query()->findOrFail($user);

        // User no lleva scope de cuenta —el login ocurre antes de que haya
        // una—, así que la frontera se comprueba a mano.
        abort_unless((int) $user->tenant_id === (int) $negocio->id && ! $user->is_platform_admin, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i',
                Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(array_keys(RoleTemplate::optionsForBusinessStaff()))],
        ], [
            'username.regex' => 'El usuario del POS solo admite letras, números, punto, guion y guion bajo.',
        ], ['name' => 'nombre', 'password' => 'contraseña', 'role' => 'rol']);

        // El rol PRIMERO: si degradar al último dueño va a fallar, que falle
        // antes de haber escrito nada más.
        app(AssignTenantRole::class)($user, $data['role'], $this->actor());

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->username = $data['username'] ?? null;

        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return back()->with('status', 'Usuario actualizado.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $negocio = $this->negocioDe($request, Permission::UsersManage->value);

        $user = User::query()->findOrFail($user);

        abort_unless((int) $user->tenant_id === (int) $negocio->id && ! $user->is_platform_admin, 404);

        if ($user->id === $request->user()?->id) {
            return back()->withErrors(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        if (app(TenantOwners::class)->isLastOwner($user)) {
            return back()->withErrors([
                'user' => 'Es la única cuenta de dueño: nombra otro dueño antes de eliminarla.',
            ]);
        }

        $user->delete();

        return back()->with('status', 'Usuario eliminado.');
    }
}
