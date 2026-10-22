<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Modules\Notifications\Domain\Contracts\FailedJobsInterface;
use Illuminate\Database\ConnectionInterface;

/** Module 16 · 3 (`D-153`) — Laravel's `failed_jobs`, read only; the payload's `displayName` is the job class. */
final class DatabaseFailedJobs implements FailedJobsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function classesStillFailed(): array
    {
        /** @var list<string> $classes */
        $classes = $this->connection->table('failed_jobs')
            ->selectRaw("DISTINCT (payload::jsonb ->> 'displayName') AS class")
            ->pluck('class')
            ->filter(static fn (mixed $class): bool => is_string($class) && $class !== '')
            ->values()
            ->all();

        return $classes;
    }
}
