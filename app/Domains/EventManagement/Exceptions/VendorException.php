<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Exceptions;

use RuntimeException;

class VendorException extends RuntimeException
{
    public static function onlyInOrganizerAccounts(): self
    {
        return new self(
            'Los negocios participantes solo existen en cuentas de organizador: '.
            'un bar independiente de la plataforma es una cuenta propia, no un negocio de evento.'
        );
    }

    public static function outletNeedsAVendor(): self
    {
        return new self(
            'Un punto de venta de evento pertenece al negocio que lo atiende: '.
            'créalo desde la participación del negocio en el evento.'
        );
    }

    public static function vendorNotInEvent(string $vendor, string $event): self
    {
        return new self(
            "[{$vendor}] no participa en [{$event}]: invítalo al evento antes de darle puntos de venta."
        );
    }

    public static function vendorOutsideTenant(): self
    {
        return new self('El negocio indicado no pertenece a esta cuenta.');
    }

    public static function vendorIsImmutable(): self
    {
        return new self(
            'Una fila no cambia de negocio: su catálogo, su stock y sus ventas dependen de a quién pertenece.'
        );
    }
}
