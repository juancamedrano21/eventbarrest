<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A quién se le puede asignar un rol: al equipo de la cuenta, al personal de
 * un comercio del evento, o a ambos. Es la frontera que impide que un rol de
 * cuenta baje a un comercio (y viceversa) también para los roles creados por
 * el superadmin.
 */
enum RoleKind: string implements HasLabel
{
    case Account = 'account';
    case Vendor = 'vendor';
    case Both = 'both';

    public function getLabel(): string
    {
        return match ($this) {
            self::Account => 'Equipo de la cuenta',
            self::Vendor => 'Personal de comercio',
            self::Both => 'Ambos',
        };
    }

    public function assignableToAccountStaff(): bool
    {
        return $this !== self::Vendor;
    }

    public function assignableToVendorStaff(): bool
    {
        return $this !== self::Account;
    }
}
