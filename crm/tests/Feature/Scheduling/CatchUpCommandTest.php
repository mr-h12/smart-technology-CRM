<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Deals\Presentation\RecomputeStaleCustomerStatusesJob;
use App\Modules\Quotations\Presentation\ExpireQuotationsJob;
use App\Modules\Reports\Presentation\ArchiveOldReportsJob;
use App\Modules\Reports\Presentation\GenerateWeeklyReportsJob;
use App\Modules\Reports\Presentation\MarkMissedDailyReportsJob;
use App\Modules\Reports\Presentation\SubmitUnreviewedWeeklyReportsJob;
use App\Support\Queue\QueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Module 13, slice 7 · 7.1 — one startup catch-up (`D-55`, `ST-05`) for every
 * scheduled job that has one: `schedule:catch-up` replaces the four per-job
 * commands (`quotations:expire`, `reports:mark-missed`,
 * `reports:generate-weekly`, `reports:submit-unreviewed`). 7.4 adds J-08
 * (`archive_old_reports`, the owner, 2026-10-18), last. J-02 has no
 * catch-up (`routes/console.php`). A missing entry fails loudly, as each
 * per-job command did.
 */
final class CatchUpCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_catch_up_dispatches_j01_j04_j05_j06_and_j08_onto_maintenance_in_that_order(): void
    {
        Queue::fake();

        self::assertSame(0, Artisan::call('schedule:catch-up'));

        Queue::assertPushedOn(QueueName::Maintenance->value, ExpireQuotationsJob::class);
        Queue::assertPushedOn(QueueName::Maintenance->value, MarkMissedDailyReportsJob::class);
        Queue::assertPushedOn(QueueName::Maintenance->value, GenerateWeeklyReportsJob::class);
        Queue::assertPushedOn(QueueName::Maintenance->value, SubmitUnreviewedWeeklyReportsJob::class);
        Queue::assertPushedOn(QueueName::Maintenance->value, ArchiveOldReportsJob::class);

        self::assertMatchesRegularExpression(
            '/J-01 dispatched\..*J-04 dispatched\..*J-05 dispatched\..*J-06 dispatched\..*J-08 dispatched\./s',
            Artisan::output(),
        );
    }

    public function test_that_the_catch_up_leaves_j02_alone_because_it_has_no_catch_up(): void
    {
        Queue::fake();

        Artisan::call('schedule:catch-up');

        Queue::assertNotPushed(RecomputeStaleCustomerStatusesJob::class);
    }

    /** Not a silent success: `&&` in the `scheduler` service stops on it. */
    public function test_that_the_catch_up_fails_loudly_and_names_the_missing_entry_when_the_schedule_is_empty(): void
    {
        Queue::fake();
        $this->app->instance(Schedule::class, new Schedule);

        self::assertSame(1, Artisan::call('schedule:catch-up'));
        self::assertStringContainsString('J-01', Artisan::output());

        Queue::assertNothingPushed();
    }
}
