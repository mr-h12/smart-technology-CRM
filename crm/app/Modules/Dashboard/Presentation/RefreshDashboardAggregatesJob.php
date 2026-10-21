<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Presentation;

use App\Modules\Dashboard\Application\RefreshDashboardAggregates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-09`'s queued entry point (§15, `D-146`): hourly, and in full nightly, onto
 * `maintenance` (§15.1: aggregates). No catch-up entry — the next run recomputes.
 */
final class RefreshDashboardAggregatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly bool $full = false) {}

    public function handle(RefreshDashboardAggregates $refresh): void
    {
        $days = $refresh->run($this->full);
        Log::info($days === null ? 'J-09: rebuilt every day.' : "J-09: rebuilt {$days} day(s).");
    }
}
