<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// is_platform_admin queda fuera de Fillable a propósito: un privilegio nunca
// se asigna por mass assignment; solo seeders/acciones de plataforma con forceFill.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
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

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            // El panel de plataforma es solo para el staff del SaaS.
            'admin' => $this->is_platform_admin === true,
            // El panel del tenant se abrirá a usuarios de negocio cuando el
            // dominio Identity los modele (hito 4); mientras tanto, cerrado
            // fuera del staff para no dejar un panel huérfano accesible.
            'app' => $this->is_platform_admin === true,
            default => false,
        };
    }
}
