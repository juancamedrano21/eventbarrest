<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
 * tenant_id e is_platform_admin quedan fuera de Fillable: pertenencia y
 * privilegio nunca se asignan por mass assignment.
 *
 * @property int|null $tenant_id
 * @property bool $is_platform_admin
 * @property string $email
 * @property string $name
 * @property-read Tenant|null $tenant
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
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
            'app' => $this->tenant !== null
                && $this->tenant->status !== TenantStatus::Suspended,
            default => false,
        };
    }
}
