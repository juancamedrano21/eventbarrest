<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Exceptions\RoleTemplateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Una plantilla de rol de la plataforma: define qué puede hacer el rol
 * (permisos) y a quién se le asigna (kind). El aprovisionamiento la
 * materializa como fila de spatie en cada cuenta, siempre con el mismo name.
 *
 * Modelo de PLATAFORMA, sin tenant: lo administra solo el superadmin. Los
 * roles de sistema (los del enum Role) se pueden ajustar pero no eliminar,
 * y el de dueño ni siquiera ajustar — es la raíz de cada cuenta.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property RoleKind $kind
 * @property bool $is_system
 * @property array<int, string> $permissions
 */
class RoleTemplate extends Model
{
    protected $fillable = ['label', 'description', 'permissions'];

    protected function casts(): array
    {
        return [
            'kind' => RoleKind::class,
            'is_system' => 'boolean',
            'permissions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RoleTemplate $template): void {
            if ($template->getAttribute('name') === null) {
                $template->setAttribute('name', Str::slug($template->label, '_'));
            }

            if ($template->name === '') {
                throw RoleTemplateException::labelNeedsLetters($template->label);
            }

            // Los identificadores del código son de las plantillas de
            // sistema: nadie los captura antes de que se siembren (una
            // plataforma virgen donde alguien crea «Owner» dejaría a todas
            // las cuentas sin poder tener dueño).
            if (! $template->is_system && in_array($template->name, RoleEnum::values(), true)) {
                throw RoleTemplateException::nameReservedForSystem($template->name);
            }

            if (static::query()->where('name', $template->name)->exists()) {
                throw RoleTemplateException::nameTaken($template->name);
            }

            // Tampoco se adopta un rol de spatie preexistente que no nació
            // de plantilla: propagar lo pisaría y el borrado se lo llevaría.
            if (! $template->is_system && SpatieRole::query()->where('name', $template->name)->exists()) {
                throw RoleTemplateException::nameTaken($template->name);
            }

            if ($template->getAttribute('kind') === null) {
                $template->setAttribute('kind', RoleKind::Account);
            }

            $template->permissions = self::assertValidPermissions($template->permissions ?? [], $template->kind);
        });

        static::updating(function (RoleTemplate $template): void {
            if ($template->name === RoleEnum::Owner->value) {
                throw RoleTemplateException::ownerIsUntouchable();
            }

            if ($template->isDirty('name') || $template->isDirty('kind') || $template->isDirty('is_system')) {
                throw RoleTemplateException::identityIsImmutable();
            }

            if ($template->isDirty('permissions')) {
                $template->permissions = self::assertValidPermissions($template->permissions, $template->kind);
            }
        });

        static::deleting(function (RoleTemplate $template): void {
            if ($template->is_system) {
                throw RoleTemplateException::systemTemplateCannotBeDeleted($template->label);
            }

            if ($template->assignedUsersCount() > 0) {
                throw RoleTemplateException::templateInUse($template->label);
            }
        });

        // Sin usuarios asignados (lo garantiza el guard), las filas de
        // spatie que la materializaban en cada cuenta se retiran también —
        // solo las materializadas: por cuenta (tenant_id) y guard web.
        static::deleted(function (RoleTemplate $template): void {
            SpatieRole::query()
                ->where('name', $template->name)
                ->where('guard_name', 'web')
                ->whereNotNull('tenant_id')
                ->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Válidos contra el catálogo del código Y contra el alcance: un permiso
     * de administración de cuenta jamás entra en una plantilla asignable a
     * personal de comercio — la frontera no se cruza ni componiendo roles.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private static function assertValidPermissions(array $permissions, RoleKind $kind): array
    {
        $clean = array_values(array_unique($permissions));

        if ($clean === []) {
            throw RoleTemplateException::needsAtLeastOnePermission();
        }

        foreach ($clean as $permission) {
            $case = Permission::tryFrom((string) $permission);

            if ($case === null) {
                throw RoleTemplateException::unknownPermission((string) $permission);
            }

            if ($kind !== RoleKind::Account && $case->accountOnly()) {
                throw RoleTemplateException::permissionNotForKind($case->value, $kind->value);
            }
        }

        return $clean;
    }

    /**
     * Cuántas asignaciones vivas tiene este rol en toda la plataforma. Sin
     * filtro de model_type a propósito: cualquier titular, del tipo que
     * sea, hace al rol «en uso».
     */
    public function assignedUsersCount(): int
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $this->name)
            ->distinct()
            ->count('model_has_roles.model_id');
    }

    /**
     * Resuelve un rol asignable por nombre, sembrando primero las plantillas
     * de sistema si la plataforma aún no las tiene.
     */
    public static function resolveOrFail(string $name): self
    {
        static::ensureSystemTemplates();

        return static::query()->where('name', $name)->first()
            ?? throw RoleTemplateException::unknownRole($name);
    }

    /**
     * Siembra los roles del código como plantillas de sistema — solo los
     * que falten: jamás pisa lo que el superadmin haya ajustado, y es
     * autocurativa: una siembra interrumpida o un rol nuevo del enum se
     * completan en la siguiente llamada (la salida rápida exige que estén
     * TODOS, no alguno).
     */
    public static function ensureSystemTemplates(): void
    {
        $existing = static::query()
            ->whereIn('name', RoleEnum::values())
            ->pluck('name');

        if ($existing->count() === count(RoleEnum::cases())) {
            return;
        }

        foreach (RoleEnum::cases() as $case) {
            if ($existing->contains($case->value)) {
                continue;
            }

            try {
                $template = new self([
                    'label' => $case->getLabel(),
                    'description' => $case->description(),
                    'permissions' => $case->permissions(),
                ]);
                $template->forceFill([
                    'name' => $case->value,
                    'kind' => self::systemKindFor($case)->value,
                    'is_system' => true,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // Otra petición sembró este rol primero: mismo resultado.
            }
        }
    }

    private static function systemKindFor(RoleEnum $role): RoleKind
    {
        return match ($role) {
            RoleEnum::VendorManager => RoleKind::Vendor,
            RoleEnum::Warehouse, RoleEnum::Cashier => RoleKind::Both,
            default => RoleKind::Account,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForAccountStaff(): array
    {
        static::ensureSystemTemplates();

        return static::query()
            ->where('kind', '!=', RoleKind::Vendor->value)
            ->orderBy('label')
            ->pluck('label', 'name')
            ->all();
    }

    /**
     * Los roles que se pueden dar en una cuenta de negocio: los de cuenta,
     * menos los que solo existen donde hay eventos y comercios invitados.
     *
     * @return array<string, string>
     */
    public static function optionsForBusinessStaff(): array
    {
        static::ensureSystemTemplates();

        return static::query()
            ->where('kind', '!=', RoleKind::Vendor->value)
            ->whereNotIn('name', RoleEnum::eventWorldOnly())
            ->orderBy('label')
            ->pluck('label', 'name')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForVendorStaff(): array
    {
        static::ensureSystemTemplates();

        return static::query()
            ->where('kind', '!=', RoleKind::Account->value)
            ->orderBy('label')
            ->pluck('label', 'name')
            ->all();
    }

    public static function labelFor(string $name): ?string
    {
        return static::query()->where('name', $name)->value('label');
    }

    public static function descriptionFor(string $name): ?string
    {
        return static::query()->where('name', $name)->value('description');
    }
}
