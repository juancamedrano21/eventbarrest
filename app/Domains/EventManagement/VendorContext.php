<?php

declare(strict_types=1);

namespace App\Domains\EventManagement;

use App\Domains\EventManagement\Models\Vendor;
use Closure;

/**
 * El segundo nivel de aislamiento: dentro de una cuenta de organizador, qué
 * negocio está operando. Los usuarios de un negocio lo tienen fijado
 * siempre; el equipo del organizador lo fija al entrar en un negocio y lo
 * deja vacío cuando mira el consolidado de su cuenta.
 *
 * Scoped (no singleton) igual que TenantContext: no se filtra entre
 * peticiones ni entre jobs.
 */
class VendorContext
{
    private ?Vendor $vendor = null;

    public function set(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }

    public function clear(): void
    {
        $this->vendor = null;
    }

    public function check(): bool
    {
        return $this->vendor !== null;
    }

    public function current(): ?Vendor
    {
        return $this->vendor;
    }

    public function id(): ?int
    {
        return $this->vendor?->id;
    }

    public function runAs(Vendor $vendor, Closure $callback): mixed
    {
        $previous = $this->vendor;
        $this->vendor = $vendor;

        try {
            return $callback();
        } finally {
            $this->vendor = $previous;
        }
    }

    /** Ejecuta con la vista consolidada de la cuenta (sin negocio activo). */
    public function runWithoutVendor(Closure $callback): mixed
    {
        $previous = $this->vendor;
        $this->vendor = null;

        try {
            return $callback();
        } finally {
            $this->vendor = $previous;
        }
    }
}
