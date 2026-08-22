<?php

declare(strict_types=1);

namespace App\Support\Seeding;

use RuntimeException;

/**
 * A seeder carrying test data was invoked against production.
 *
 * It fails loudly rather than skipping quietly. A seeder that silently does
 * nothing in production is indistinguishable, in a deployment log, from one
 * that worked — and the difference only surfaces later, as eight fictional
 * employees in a real user list.
 */
final class ProductionSeedRefused extends RuntimeException
{
    public function __construct(string $seeder)
    {
        parent::__construct(
            "Refusing to run {$seeder}: it seeds test data (DEV-08) and this is the production "
            .'environment. Reference data — roles, permissions, managed lists and currencies — '
            .'belongs in production and is not affected; a seeder is only refused here when its '
            .'own seedsTestData() says the rows are fixtures.',
        );
    }
}
