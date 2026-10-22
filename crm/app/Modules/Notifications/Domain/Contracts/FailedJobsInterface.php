<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Contracts;

/** Module 16 · 3 (`D-153`) — what the queue still holds as failed, read for the sweep. */
interface FailedJobsInterface
{
    /**
     * The job classes with at least one row left in `failed_jobs`.
     *
     * @return list<string>
     */
    public function classesStillFailed(): array;
}
