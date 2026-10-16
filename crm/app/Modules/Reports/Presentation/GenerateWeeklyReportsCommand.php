<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * `J-05`'s startup catch-up (`D-55`, `ST-05`, `D-136`): the `scheduler` service runs it
 * once before `schedule:work`, so a week ended while the stack was down is drafted on start rather than next Sunday.
 *
 * It runs `routes/console.php`'s own `J-05` entry, as {@see MarkMissedDailyReportsCommand}
 * does, and fails loudly when the entry is gone rather than skipping the catch-up in silence.
 */
final class GenerateWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:generate-weekly';

    protected $description = 'J-05: run the scheduled generate_weekly_reports entry now (the startup catch-up)';

    public function handle(Schedule $schedule): int
    {
        foreach ($schedule->events() as $event) {
            if ($event->description === GenerateWeeklyReportsJob::class) {
                $event->run($this->laravel);
                $this->info('J-05 dispatched.');

                return self::SUCCESS;
            }
        }

        $this->error('J-05 is not scheduled.');

        return self::FAILURE;
    }
}
