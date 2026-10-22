<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\ClearResolvedAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-17`'s queued entry point (`D-153`): hourly onto `maintenance` (§15.1), the
 * run logged with its outcome. No catch-up entry — the next hour's run is it.
 */
final class ClearResolvedAlertsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(ClearResolvedAlerts $sweep): void
    {
        $cleared = $sweep->run();
        Log::info("J-17: cleared {$cleared['account_locked']} account-locked and {$cleared['job_failed']} job-failed alert(s).");
    }
}
