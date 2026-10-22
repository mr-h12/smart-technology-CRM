<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\RaiseQuotationExpiryAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-16`'s queued entry point (`D-153`), `J-01`'s shape: onto `maintenance`
 * (§15.1), retried by the worker's `--tries=3`, the run logged with its outcome.
 * Dispatched daily by `routes/console.php` and once on start by
 * `schedule:catch-up` (`D-55`), after `J-01`.
 */
final class RaiseQuotationExpiryAlertsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(RaiseQuotationExpiryAlerts $alerts): void
    {
        $result = $alerts->run();
        Log::info("J-16: raised {$result['raised']}, cleared {$result['cleared']} quotation expiry alert(s).");
    }
}
