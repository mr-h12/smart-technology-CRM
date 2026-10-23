<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Backups\RunBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-10`'s queued entry point (`D-153`): daily at 02:00 UTC onto `maintenance`
 * (§15.1), and once on start by `schedule:catch-up` (§15 ✅) — the job itself skips
 * a day already backed up. A failed backup is recorded and announced, not thrown,
 * so the worker's three tries never become three backups.
 */
final class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Under the redis connection's `retry_after` (90): a slower run must not be handed out twice. */
    public int $timeout = 85;

    public function handle(RunBackup $backup): void
    {
        Log::info('J-10: backup '.$backup->run().'.');
    }
}
