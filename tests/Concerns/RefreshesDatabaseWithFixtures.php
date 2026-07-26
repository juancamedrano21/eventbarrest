<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, plus the test-only fixture migrations.
 *
 * The path is registered in beforeRefreshingDatabase() so fixture tables are
 * created by the migrator, outside the per-test transaction — rather than
 * with Schema::create() inside it, which only works because SQLite happens
 * to support transactional DDL.
 */
trait RefreshesDatabaseWithFixtures
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        // The 'migrator' binding, not the class name: Migrator is not aliased,
        // so make(Migrator::class) would build a throwaway instance and the
        // path would be silently ignored.
        $this->app->make('migrator')->path(base_path('tests/Fixtures/migrations'));
    }
}
