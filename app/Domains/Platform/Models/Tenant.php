<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string|null $rnc
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'name',
        'rnc',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('platform');
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
