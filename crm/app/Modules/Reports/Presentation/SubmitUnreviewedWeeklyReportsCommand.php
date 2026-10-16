<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * `J-06`'s startup catch-up (`D-55`, `ST-05`, `D-136`): the `scheduler` service runs it
 * once before `schedule:work`, so a review window that closed while the stack was down is acted on at start rather than at the next midnight.
 *
 * It runs `routes/console.php`'s own `J-06` entry, as {@see MarkMissedDailyReportsCommand}
 * does, and fails loudly when the entry is gone rather than skipping the catch-up in silence.
 */
final class SubmitUnreviewedWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:submit-unreviewed';

    protected $description = 'J-06: run the scheduled submit_unreviewed_weekly_reports entry now (the startup catch-up)';

    public function handle(Schedule $schedule): int
    {
        foreach ($schedule->events() as $event) {
            if ($event->description === SubmitUnreviewedWeeklyReportsJob::class) {
                $event->run($this->laravel);
                $this->info('J-06 dispatched.');

                return self::SUCCESS;
            }
        }

        $this->error('J-06 is not scheduled.');

        return self::FAILURE;
    }
}
