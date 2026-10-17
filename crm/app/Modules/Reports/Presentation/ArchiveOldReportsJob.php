<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\ArchiveOldReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-08`'s queued entry point, `J-04`'s shape: onto `maintenance` (§15.1),
 * retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched daily by `routes/console.php` and once on start by
 * `schedule:catch-up` (`D-55`).
 */
final class ArchiveOldReportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(ArchiveOldReports $archive): void
    {
        $count = $archive->run();

        Log::info("J-08: archived {$count} report(s).");
    }
}
