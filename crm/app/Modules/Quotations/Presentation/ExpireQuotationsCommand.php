<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * `J-01`'s startup catch-up (`D-55`, `ST-05`): the `scheduler` service runs it
 * once before `schedule:work`, so a day missed while the stack was down is
 * repaired on start rather than at the next midnight.
 *
 * It runs `routes/console.php`'s own `J-01` entry, so the catch-up is queued
 * where the daily run is (owner, 2026-09-23, Q-B) and the queue is named once.
 * Not `schedule:test --name=`, which answers a missing entry with exit 0: a
 * renamed job would skip the catch-up in silence. Here it stops the service.
 */
final class ExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'J-01: run the scheduled expire_quotations entry now (the startup catch-up)';

    public function handle(Schedule $schedule): int
    {
        foreach ($schedule->events() as $event) {
            if ($event->description === ExpireQuotationsJob::class) {
                $event->run($this->laravel);
                $this->info('J-01 dispatched.');

                return self::SUCCESS;
            }
        }

        $this->error('J-01 is not scheduled.');

        return self::FAILURE;
    }
}
