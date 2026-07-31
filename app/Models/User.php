<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Queries\UserRoles;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de la plataforma o de un negocio.
 *
 * Deliberadamente NO usa BelongsToTenant: la autenticación ocurre antes de
 * que exista contexto de tenant, así que un scope fail-closed impediría el
 * propio login. El aislamiento se garantiza donde importa — el middleware
 * SetTenantContext fija el tenant del usuario, y las consultas de usuarios
 * dentro del panel de negocio se acotan explícitamente en su Resource.
 *
 * tenant_id, vendor_id e is_platform_admin quedan fuera de Fillable:
 * pertenencia y privilegio nunca se asignan por mass assignment.
 *
 * @property int|null $tenant_id
 * @property int|null $vendor_id
 * @property bool $is_platform_admin
 * @property string $email
 * @property string $name
 * @property-read Tenant|null $tenant
 * @property-read Vendor|null $vendor
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'tenant_id' => 'integer',
            'vendor_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // La pertenencia a un comercio es coherente o no es: el comercio debe
        // existir en la MISMA cuenta del usuario, y el staff de plataforma no
        // pertenece a ninguno. Consulta cruda a propósito: este guard corre
        // también sin contexto de tenant (panel admin, comandos, jobs) y ahí
        // el scope de Vendor fallaría cerrado.
        static::saving(function (User $user): void {
            // Solo cuando el save toca la pertenencia: el ciclo del remember
            // token (login/logout) no re-valida un estado que no cambia.
            if (! $user->isDirty(['vendor_id', 'tenant_id', 'is_platform_admin'])) {
                return;
            }

            if ($user->vendor_id === null) {
                return;
            }

            if ($user->is_platform_admin) {
                throw VendorException::staffCannotJoinVendor();
            }

            $vendorTenant = DB::table('vendors')
                ->where('id', $user->vendor_id)
                ->value('tenant_id');

            if ($vendorTenant === null || (int) $vendorTenant !== $user->tenant_id) {
                throw VendorException::userOutsideTenant();
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * El comercio al que pertenece, si es personal de uno. Vendor lleva
     * TenantScope: esta relación solo carga con el contexto de tenant fijado.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function worksForAVendor(): bool
    {
        return $this->vendor_id !== null;
    }

    /**
     * Roles cuyo trabajo entero ocurre en el POS: hoy, el cajero. Entrar al
     * panel de gestión solo les mostraría un menú vacío.
     */
    public function onlyOperatesThePos(): bool
    {
        // Consulta explícita, no getRoleNames(): canAccessPanel se evalúa al
        // autenticar, antes de que el middleware fije el equipo de permisos,
        // y ahí la relación de roles vendría vacía.
        $roles = app(UserRoles::class)->namesFor($this);

        return $roles->isNotEmpty()
            && $roles->diff([Role::Cashier->value])->isEmpty();
    }

    public function isPlatformStaff(): bool
    {
        return $this->is_platform_admin === true;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            // El panel de plataforma es solo para el staff del SaaS.
            'admin' => $this->isPlatformStaff(),
            // El panel del negocio exige pertenecer a un tenant que no esté
            // suspendido: suspender corta el acceso de todo su equipo.
            // El cajero queda fuera a propósito: su trabajo ocurre en el POS,
            // y aquí solo vería un panel sin una sola pantalla.
            'app' => $this->tenant !== null
                && $this->tenant->status !== TenantStatus::Suspended
                && ! $this->vendorIsSuspended()
                && ! $this->onlyOperatesThePos(),
            default => false,
        };
    }

    /**
     * Suspender un comercio corta el acceso de su gente, igual que suspender
     * la cuenta corta el de todo su equipo. Consulta cruda: la autenticación
     * ocurre sin contexto de tenant y el scope de Vendor fallaría cerrado.
     */
    private function vendorIsSuspended(): bool
    {
        if ($this->vendor_id === null) {
            return false;
        }

        return DB::table('vendors')->where('id', $this->vendor_id)->value('status')
            === VendorStatus::Suspended->value;
    }
}
