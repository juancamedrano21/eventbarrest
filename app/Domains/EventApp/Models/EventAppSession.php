<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una sesión abierta de la app: el token de larga vida del teléfono.
 *
 * El patrón es el de la casa (kds_devices), no Sanctum, y por los mismos dos
 * motivos documentados en AuthenticateKdsDevice: `guard => ['web']` haría que
 * una sesión web abierta autenticase a ESA persona sin código, y
 * `sanctum:prune-expired` borra por `created_at` ignorando la vida real del
 * token — todas las sesiones morirían en silencio a los quince días.
 *
 * En la base solo vive el sha256 del token; el valor en claro existe una vez,
 * al entrar, y viaja al Keychain del teléfono. Perderlo no se recupera: se
 * entra de nuevo con otro código.
 *
 * @property int $id
 * @property int $event_app_account_id
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property-read EventAppAccount|null $cuenta
 */
class EventAppSession extends Model
{
    protected $fillable = [
        'event_app_account_id',
        'token_hash',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Revocada = 401 en la siguiente petición. El middleware lo mira siempre. */
    public function estaRevocada(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<EventAppAccount, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(EventAppAccount::class, 'event_app_account_id');
    }
}
