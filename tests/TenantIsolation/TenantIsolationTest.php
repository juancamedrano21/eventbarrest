<?php

declare(strict_types=1);

use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\Exceptions\CrossTenantWriteException;
use App\Domains\Tenancy\Exceptions\MissingTenantContextException;
use App\Domains\Tenancy\Exceptions\UnsafeBulkWriteException;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Gadget;

beforeEach(function (): void {
    $this->context = app(TenantContext::class);
    $this->tenantA = Tenant::factory()->create(['name' => 'Bar A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Bar B']);

    $this->asA = fn (callable $fn) => $this->context->runAs($this->tenantA, $fn);
    $this->asB = fn (callable $fn) => $this->context->runAs($this->tenantB, $fn);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

describe('reads', function (): void {
    it('hides other tenants rows from queries', function (): void {
        ($this->asA)(fn () => Gadget::create(['name' => 'De A']));
        ($this->asB)(fn () => Gadget::create(['name' => 'De B']));

        $this->context->set($this->tenantA);

        expect(Gadget::pluck('name')->all())->toBe(['De A']);
    });

    it('returns nothing when no tenant context is active', function (): void {
        ($this->asA)(fn () => Gadget::create(['name' => 'De A']));

        $this->context->clear();

        expect(Gadget::count())->toBe(0);
    });

    it('cannot find another tenants row by id', function (): void {
        $foreign = ($this->asB)(fn () => Gadget::create(['name' => 'De B']));

        $this->context->set($this->tenantA);

        expect(Gadget::find($foreign->id))->toBeNull();
    });

    it('scopes lookups per tenant with identical-looking data', function (): void {
        ($this->asA)(fn () => Gadget::create(['name' => 'Mojito']));
        ($this->asB)(fn () => Gadget::create(['name' => 'Mojito']));

        $this->context->set($this->tenantB);
        $found = Gadget::where('name', 'Mojito')->get();

        expect($found)->toHaveCount(1)
            ->and($found->first()->tenant_id)->toBe($this->tenantB->id);
    });

    it('exposes all rows only through the explicit escape hatch', function (): void {
        ($this->asA)(fn () => Gadget::create(['name' => 'De A']));
        ($this->asB)(fn () => Gadget::create(['name' => 'De B']));

        $this->context->clear();

        expect(Gadget::query()->withoutTenancy()->count())->toBe(2);
    });
});

describe('writes are fail-closed', function (): void {
    it('fills tenant_id from the active context on create', function (): void {
        $this->context->set($this->tenantA);

        expect(Gadget::create(['name' => 'Mesa 1'])->tenant_id)->toBe($this->tenantA->id);
    });

    it('refuses to create without a tenant context', function (): void {
        Gadget::create(['name' => 'Huérfano']);
    })->throws(MissingTenantContextException::class);

    it('refuses an explicit tenant_id belonging to another tenant', function (): void {
        $this->context->set($this->tenantA);

        Gadget::forceCreate(['tenant_id' => $this->tenantB->id, 'name' => 'Plantado por A']);
    })->throws(CrossTenantWriteException::class);

    it('refuses to create for another tenant even with no context', function (): void {
        Gadget::forceCreate(['tenant_id' => $this->tenantB->id, 'name' => 'Sin contexto']);
    })->throws(MissingTenantContextException::class);

    it('ignores tenant_id coming from mass assignment', function (): void {
        $this->context->set($this->tenantA);

        $gadget = Gadget::create(['name' => 'Desde request', 'tenant_id' => $this->tenantB->id]);

        expect($gadget->tenant_id)->toBe($this->tenantA->id);
    });

    it('accepts an explicit tenant_id that matches the active context', function (): void {
        $this->context->set($this->tenantB);

        expect(Gadget::forceCreate(['tenant_id' => $this->tenantB->id, 'name' => 'Coherente'])->tenant_id)
            ->toBe($this->tenantB->id);
    });

    it('lets platform flows write for a tenant through runAs', function (): void {
        expect(($this->asB)(fn () => Gadget::create(['name' => 'Sembrado'])->tenant_id))
            ->toBe($this->tenantB->id);
    });
});

describe('tenant_id is immutable', function (): void {
    it('refuses to move a row to another tenant via save', function (): void {
        $gadget = ($this->asA)(fn () => Gadget::create(['name' => 'De A']));

        $this->context->set($this->tenantA);
        $gadget->tenant_id = $this->tenantB->id;
        $gadget->save();
    })->throws(CrossTenantWriteException::class);

    it('ignores tenant_id in an update payload because it is not fillable', function (): void {
        $gadget = ($this->asA)(fn () => Gadget::create(['name' => 'De A']));

        $this->context->set($this->tenantA);
        $gadget->update(['name' => 'Renombrado', 'tenant_id' => $this->tenantB->id]);

        expect($gadget->fresh()->tenant_id)->toBe($this->tenantA->id)
            ->and($gadget->fresh()->name)->toBe('Renombrado');
    });

    it('refuses a mass update that writes tenant_id', function (): void {
        ($this->asA)(fn () => Gadget::create(['name' => 'De A']));

        $this->context->set($this->tenantA);
        Gadget::query()->update(['tenant_id' => $this->tenantB->id]);
    })->throws(CrossTenantWriteException::class);

    it('still allows ordinary updates', function (): void {
        $gadget = ($this->asA)(fn () => Gadget::create(['name' => 'Viejo']));

        $this->context->set($this->tenantA);
        $gadget->update(['name' => 'Nuevo']);

        expect($gadget->fresh()->name)->toBe('Nuevo');
    });
});

describe('bulk write paths that bypass Eloquent events', function (): void {
    it('blocks insert on a scoped model', function (): void {
        $this->context->set($this->tenantA);

        Gadget::insert([['name' => 'Masivo', 'tenant_id' => $this->tenantA->id]]);
    })->throws(UnsafeBulkWriteException::class);

    it('blocks upsert on a scoped model', function (): void {
        $this->context->set($this->tenantA);

        Gadget::upsert([['tenant_id' => $this->tenantA->id, 'code' => 'MOJITO', 'name' => 'Mojito']], ['code'], ['name']);
    })->throws(UnsafeBulkWriteException::class);

    it('keeps tenants apart when the unique index is composite', function (): void {
        // The schema convention is the real defence: with unique(tenant_id, code)
        // a conflict can never resolve against another tenant's row.
        ($this->asA)(fn () => Gadget::create(['name' => 'Mojito de A', 'code' => 'MOJITO']));
        ($this->asB)(fn () => Gadget::create(['name' => 'Mojito de B', 'code' => 'MOJITO']));

        expect(DB::table('test_gadgets')->where('code', 'MOJITO')->count())->toBe(2);
    });
});

describe('mass update and delete', function (): void {
    it('cannot update or delete across tenants', function (): void {
        $foreign = ($this->asB)(fn () => Gadget::create(['name' => 'De B']));

        $this->context->set($this->tenantA);

        $updated = Gadget::query()->whereKey($foreign->id)->update(['name' => 'Hackeado']);
        $deleted = Gadget::query()->whereKey($foreign->id)->delete();

        expect($updated)->toBe(0)
            ->and($deleted)->toBe(0)
            ->and(($this->asB)(fn () => Gadget::sole()->name))->toBe('De B');
    });
});

describe('context lifecycle', function (): void {
    it('restores the previous context after runAs, even on failure', function (): void {
        $this->context->set($this->tenantA);

        try {
            $this->context->runAs($this->tenantB, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect($this->context->current()?->id)->toBe($this->tenantA->id);
    });

    it('is resolved fresh per container scope so it cannot leak', function (): void {
        $this->context->set($this->tenantA);

        $leaked = null;
        $this->app->forgetScopedInstances();
        $leaked = app(TenantContext::class)->current();

        expect($leaked)->toBeNull();
    });
});
