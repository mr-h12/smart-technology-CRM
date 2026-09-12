<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Tests\TestCase;

/** Temporary: proves a failing test turns its shard red. Reverted in the next commit. */
final class VerifierProbeTest extends TestCase
{
    public function test_that_the_shard_can_fail(): void
    {
        self::assertTrue(false, 'deliberate — CI sharding verifier');
    }
}
