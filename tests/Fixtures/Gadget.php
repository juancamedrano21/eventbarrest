<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only tenant-scoped model backing the TenantIsolation suite.
 *
 * tenant_id is deliberately absent from $fillable: business models never
 * accept it from mass assignment — the trait fills it from the context.
 */
class Gadget extends Model
{
    use BelongsToTenant;

    protected $table = 'test_gadgets';

    protected $fillable = ['name', 'code'];

    public $timestamps = false;
}
