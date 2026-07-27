<?php

declare(strict_types=1);

namespace App\Domains\Business\Models;

use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Tenancy\TenantContext;
use App\Support\Eloquent\IsChildModel;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Sucursal: la unidad operativa del mundo de los negocios. No existe la
 * posibilidad de colgarla de un evento — esta clase no sabe lo que es uno.
 */
class Branch extends OperatingUnit
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use IsChildModel;

    public static function childTypeValue(): string
    {
        return OperatingUnitType::Branch->value;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Branch $branch): void {
            // Estructural, no elegible: una sucursal jamás cuelga de un evento.
            $branch->event_id = null;

            // El contexto ya tiene la cuenta hidratada como su clase de
            // mundo, y BelongsToTenant garantizó que coincide con tenant_id.
            $tenant = app(TenantContext::class)->current();

            if ($tenant !== null && ! $tenant instanceof BusinessAccount) {
                throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
            }
        });
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
