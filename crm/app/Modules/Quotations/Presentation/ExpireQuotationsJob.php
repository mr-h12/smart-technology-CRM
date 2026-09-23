<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Quotations\Application\Writing\ExpireQuotations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-01`'s queued entry point, `J-02`'s shape: onto `maintenance` (§15.1),
 * retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched daily by `routes/console.php` and once on start by
 * {@see ExpireQuotationsCommand} (`D-55`).
 */
final class ExpireQuotationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(ExpireQuotations $expire): void
    {
        $count = $expire->run();

        Log::info("J-01: expired {$count} quotation(s).");
    }
}
