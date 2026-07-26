<?php

declare(strict_types=1);

use Tests\Concerns\RefreshesDatabaseWithFixtures;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshesDatabaseWithFixtures::class)
    ->in('Feature', 'TenantIsolation');
