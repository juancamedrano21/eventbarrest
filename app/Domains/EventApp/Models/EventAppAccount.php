<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La cuenta del asistente: el primer actor de plataforma que no es el
 * superadmin.
 *
 * SIN BelongsToTenant, Y NO ES UN OLVIDO. Todo lo demás que camina por la
 * plataforma pertenece a una cuenta de negocio: el staff (`users`), las
 * tabletas, las ventas. El asistente no. Es la identidad que mañana ata
 * boleta, pulsera y monedero A TRAVÉS de eventos de organizadores distintos
 * —el asistente de Bocao es el mismo asistente en el próximo festival—, así
 * que colgarlo de un tenant lo partiría en una identidad por organizador y
 * ninguna flecha del doc 11 podría cruzar de un evento al siguiente.
 *
 * Consecuencia deliberada: quien lea esta tabla no necesita contexto de
 * tenant, y quien la escriba tampoco. La puerta del asistente no llama al
 * ContextResolver — no hay tenant que resolver.
 *
 * Tampoco tiene contraseña. Entrar es demostrar el control del buzón con un
 * código de un solo uso: no hay nada que olvidar ni que robar en un volcado.
 *
 * @property int $id
 * @property string|null $name Null = todavía no lo dijo; la app enseña el email
 * @property string $email Normalizado: minúscula y sin espacios
 */
class EventAppAccount extends Model
{
    protected $fillable = [
        'name',
        'email',
    ];

    /**
     * @return HasMany<EventAppSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventAppSession::class, 'event_app_account_id');
    }

    /**
     * La única forma de escribir un email en esta tabla, y la misma que usan
     * el freno por destino y la búsqueda del código: si se normalizara en
     * unos sitios sí y en otros no, «Ana@x.com » y «ana@x.com» serían dos
     * cuentas — y dos cubos del freno — para el mismo buzón.
     */
    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
