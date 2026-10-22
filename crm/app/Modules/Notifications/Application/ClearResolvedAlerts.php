<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Notifications\Domain\Contracts\AlertLedgerInterface;
use App\Modules\Notifications\Domain\Contracts\FailedJobsInterface;

/**
 * Module 16, Point 3 — `J-17` clear_resolved_alerts (`D-153`, approved 2026-10-22).
 *
 * The two causes that end on their own: a lock ends with time, and a failure
 * record leaves `failed_jobs` through `queue:retry` or `queue:forget`. Hourly,
 * this clears each `account_locked` alert whose account is no longer locked and
 * each `job_failed` alert whose class has no row left. Rows are kept; no other
 * kind is touched.
 */
final readonly class ClearResolvedAlerts
{
    public function __construct(
        private UserFactsInterface $users,
        private FailedJobsInterface $failedJobs,
        private AlertLedgerInterface $alerts,
    ) {}

    /** @return array{account_locked: int, job_failed: int} how many were cleared */
    public function run(): array
    {
        return [
            RaiseSystemAlerts::ACCOUNT_LOCKED => $this->alerts->clearAllBut(RaiseSystemAlerts::ACCOUNT_LOCKED, $this->users->lockedUserIds()),
            RaiseSystemAlerts::JOB_FAILED => $this->alerts->clearAllBut(RaiseSystemAlerts::JOB_FAILED, $this->failedJobs->classesStillFailed()),
        ];
    }
}
