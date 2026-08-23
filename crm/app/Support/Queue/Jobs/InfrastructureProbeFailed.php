<?php

declare(strict_types=1);

namespace App\Support\Queue\Jobs;

use RuntimeException;

/**
 * Thrown by InfrastructureProbeJob when it is asked to fail.
 *
 * A named class rather than a bare RuntimeException so a test can assert that
 * §15.1's retry bound recorded *this* failure and not some unrelated one that
 * happened to land in failed_jobs at the same moment.
 */
final class InfrastructureProbeFailed extends RuntimeException
{
    public function __construct(string $token)
    {
        parent::__construct("Infrastructure probe {$token} failed deliberately.");
    }
}
