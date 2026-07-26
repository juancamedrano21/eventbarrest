<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * insert() and upsert() bypass Eloquent events, so the tenant guard in
 * BelongsToTenant never runs for them. Worse, upsert resolves conflicts
 * through unique indexes — on MySQL the uniqueBy argument is ignored
 * entirely — so a non-composite unique index lets one tenant silently
 * overwrite another tenant's row. Both are blocked on scoped models.
 */
class UnsafeBulkWriteException extends RuntimeException
{
    public static function insert(string $model): self
    {
        return new self(
            "insert() is not allowed on the tenant-scoped model [{$model}]: it bypasses Eloquent ".
            'events, so tenant_id is never filled in. Use create()/createMany(), or DB::table() '.
            'explicitly if you genuinely need a raw insert.'
        );
    }

    public static function upsert(string $model): self
    {
        return new self(
            "upsert() is not allowed on the tenant-scoped model [{$model}]: conflicts resolve through ".
            'unique indexes (MySQL ignores uniqueBy), so it can overwrite another tenant\'s row. '.
            'Ensure every unique index is composite with tenant_id and use an explicit, reviewed '.
            'bulk path instead.'
        );
    }
}
