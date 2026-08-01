<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Abre la jornada de caja de una unidad con su fondo inicial. La unicidad
 * (una sola abierta por unidad) la garantiza el índice de BD: dos aperturas
 * simultáneas no pueden colarse.
 */
class OpenCashSession
{
    public function __invoke(OperatingUnit $unit, ?User $user, int $openingCents): CashSession
    {
        try {
            $session = new CashSession([
                'status' => CashSessionStatus::Open,
                'opening_cents' => $openingCents,
                'opened_at' => now(),
            ]);
            $session->operating_unit_id = $unit->id;
            $session->user_id = $user?->id;
            $session->save();

            return $session;
        } catch (UniqueConstraintViolationException) {
            throw SalesException::sessionAlreadyOpen($unit->name);
        }
    }
}
