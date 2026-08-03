<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\Kitchen\Models\KdsDevice;

/**
 * Apaga una tablet, de una en una.
 *
 * Se revoca, no se borra, porque las comandas guardan qué dispositivo las
 * empezó y cuál las dio por listas: borrar la fila dejaría ese rastro
 * apuntando al vacío justo cuando hace falta —al reclamar un plato que
 * nunca salió—. La revocación es idempotente y conserva la hora del primer
 * corte: revocar dos veces no reescribe cuándo dejó de entrar.
 */
class RevokeKdsDevice
{
    public function __invoke(KdsDevice $device): void
    {
        if ($device->estaRevocada()) {
            return;
        }

        $device->setAttribute('revoked_at', now());

        $device->save();
    }
}
