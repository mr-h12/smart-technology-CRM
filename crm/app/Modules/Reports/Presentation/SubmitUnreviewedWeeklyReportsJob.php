<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\SubmitUnreviewedWeeklyReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-06`'s queued entry point, `J-04`'s shape: onto `maintenance` (§15.1),
 * retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched hourly by `routes/console.php` and once on start by
 * `schedule:catch-up` (`D-55`).
 */
final class SubmitUnreviewedWeeklyReportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(SubmitUnreviewedWeeklyReports $submit): void
    {
        $count = $submit->run();

        Log::info("J-06: submitted {$count} unreviewed weekly report(s).");
    }
}
