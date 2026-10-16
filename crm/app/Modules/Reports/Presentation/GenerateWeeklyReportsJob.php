<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\GenerateWeeklyReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-05`'s queued entry point, `J-04`'s shape: onto `maintenance` (§15.1),
 * retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched weekly by `routes/console.php` and once on start by
 * {@see GenerateWeeklyReportsCommand} (`D-55`).
 */
final class GenerateWeeklyReportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(GenerateWeeklyReports $generate): void
    {
        $count = $generate->run();

        Log::info("J-05: drafted {$count} weekly report(s).");
    }
}
