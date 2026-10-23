<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\CheckStorageThreshold;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-14`'s queued entry point (`D-153`): daily onto `maintenance` (§15.1), the run
 * logged with its outcome. No catch-up entry (§15 ❌) — the next day's run is it.
 */
final class CheckStorageThresholdJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(CheckStorageThreshold $check): void
    {
        $result = $check->run();

        if ($result === null) {
            Log::warning('J-14: the files volume could not be measured; no storage alert raised or cleared.');

            return;
        }

        Log::info("J-14: raised {$result['raised']}, cleared {$result['cleared']} storage alert(s).");
    }
}
