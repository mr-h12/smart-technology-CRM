<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\GenerateMonthlyReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-07`'s queued entry point (`D-140`), `J-05`'s shape: onto `maintenance`
 * (§15.1), retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched monthly by `routes/console.php` and once on start by
 * `schedule:catch-up` (`D-55`).
 */
final class GenerateMonthlyReportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(GenerateMonthlyReports $generate): void
    {
        $count = $generate->run();

        Log::info("J-07: drafted {$count} monthly report(s).");
    }
}
