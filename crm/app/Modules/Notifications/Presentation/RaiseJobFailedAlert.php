<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\RaiseSystemAlerts;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Module 16 · 3 (`D-153`) — Laravel's `JobFailed`, fired once a job's retries are
 * spent and it is written to `failed_jobs`. Raising the alert must never turn one
 * failure into two: if the alert itself cannot be written, that is logged and the
 * worker carries on.
 */
final readonly class RaiseJobFailedAlert
{
    public function __construct(private RaiseSystemAlerts $alerts) {}

    public function handle(JobFailed $event): void
    {
        try {
            $this->alerts->jobFailed($event->job->resolveName(), $event->job->getQueue());
        } catch (Throwable $e) {
            Log::error('Module 16: the job-failed alert could not be raised.', ['job' => $event->job->resolveName(), 'error' => $e->getMessage()]);
        }
    }
}
