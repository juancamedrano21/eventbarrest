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

    public static function vendorIsNotInTheEvent(): self
    {
        return new self('Ese comercio no participa en el evento.');
    }

    public static function vendorHasAnOpenCashSession(): self
    {
        return new self(
            'Tiene una caja abierta en este evento. Ciérrala desde el POS antes de sacarlo: '
            .'si no, su cajero se queda a mitad de turno sin poder cobrar ni cuadrar.'
        );
    }

    public static function vendorIsImmutable(): self
    {
        return new self(
            'Una fila no cambia de negocio: su catálogo, su stock y sus ventas dependen de a quién pertenece.'
        );
    }

    public static function userOutsideTenant(): self
    {
        return new self('El comercio del usuario debe pertenecer a su misma cuenta.');
    }

    public static function staffCannotJoinVendor(): self
    {
        return new self('El staff de la plataforma no pertenece a ningún comercio.');
    }

    public static function roleNotForVendorStaff(string $role): self
    {
        return new self(
            "El personal de un comercio no puede tener el rol [{$role}]: ".
            'los roles de cuenta se quedan en la cuenta.'
        );
    }

    public static function roleOnlyForVendorStaff(string $role): self
    {
        return new self("El rol [{$role}] solo existe dentro de un comercio: asigna el usuario a uno.");
    }

    public static function hasUsers(string $vendor): self
    {
        return new self(
            "[{$vendor}] tiene usuarios asignados: elimínalos antes de borrar el comercio."
        );
    }

    public static function writingForAnotherVendor(): self
    {
        return new self('Con un comercio activo no se escribe a nombre de otro.');
    }
}
