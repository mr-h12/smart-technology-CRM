<?php

declare(strict_types=1);

use App\Modules\Audit\Presentation\EnsureAuditPartitionsCommand;
use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use App\Modules\Deals\Presentation\RecomputeStaleCustomerStatusesJob;
use App\Modules\Quotations\Presentation\ExpireQuotationsJob;
use App\Modules\Reports\Presentation\ArchiveOldReportsJob;
use App\Modules\Reports\Presentation\GenerateMonthlyReportsJob;
use App\Modules\Reports\Presentation\GenerateWeeklyReportsJob;
use App\Modules\Reports\Presentation\MarkMissedDailyReportsJob;
use App\Modules\Reports\Presentation\SubmitUnreviewedWeeklyReportsJob;
use App\Support\Queue\QueueName;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|------------------------------------------------------------------------------
| Scheduled jobs (§15)
|------------------------------------------------------------------------------
*/

// J-15 ensure_audit_partitions. Daily at UTC midnight — the boundary it exists
// to be ahead of — and idempotent, so running it twice on the same day is a
// no-op rather than an error (§15).
//
// Scheduled rather than queued, and the difference is deliberate. §15 says
// every job appears in Queue Monitor, which means Horizon, which is not
// installed yet; and the worker services carry a `workers` compose profile, so
// they are not running by default. A queued J-15 would therefore sit in Redis
// unexecuted while the table quietly ran out of months — the exact failure it
// exists to prevent. Moving it onto the `maintenance` queue is owed once
// Horizon lands, and is recorded as such.
Schedule::command(EnsureAuditPartitionsCommand::class)->daily();

// J-02 recompute_customer_status, the nightly half (§4.5, D-49). Deals Point
// 3.1 already wired the event-triggered half into POST /deals and PATCH
// /deals/{id}/status; this catches the one case neither event fires for — a
// customer's only active deal simply going stale with nothing else
// happening.
//
// Queued, unlike J-15 above: J-15's scheduler-run shape is §15's one
// documented exception, for a failure mode (audit_log silently running out
// of months) this job does not share — a missed night here is repaired by
// tomorrow's run or the next deal-write event, whichever comes first.
// Everything else follows §15's default: dispatched onto `maintenance`
// (QueueName::Maintenance — "cleanup, indexing, aggregates"), where the
// worker-maintenance service already drains it with its own --tries=3.
//
// No catch-up entry (D-55, ST-05), on J-15's own precedent: idempotent and
// always evaluated against *now*, so a run missed during downtime is
// repaired by the next run's fresh read of the current state, not by
// replaying the nights that did not happen.
Schedule::job(new RecomputeStaleCustomerStatusesJob, QueueName::Maintenance->value)->daily();

// J-01 expire_quotations (§15, Module 10 · 2.1). J-02's queued shape, onto
// `maintenance`. Unlike J-02 it has its catch-up (§15 ✅, D-55): the
// `scheduler` service runs `schedule:catch-up` once before `schedule:work`.
// At 00:00 UTC — the company's day is read inside the job; local-time
// scheduling was declined by the owner (2026-09-23).
Schedule::job(new ExpireQuotationsJob, QueueName::Maintenance->value)->daily();

// J-04 mark_missed_daily_reports (§15, D-135, Module 13 slice 5). J-01's shape,
// onto `maintenance`, with its catch-up (§15 ✅, D-55): the `scheduler` service
// runs `schedule:catch-up` once before `schedule:work`. At 00:00 UTC — the
// company's day is read inside the job, as J-01 does.
Schedule::job(new MarkMissedDailyReportsJob, QueueName::Maintenance->value)->daily();

// J-05 generate_weekly_reports (§15, D-136, Module 13 slice 6). J-04's shape,
// onto `maintenance`, with its catch-up (`schedule:catch-up`). Early on
// Sunday, 01:00 UTC — the company's day and week are read inside the job, as
// J-01 does. ponytail: a company zone west of UTC is still on Saturday at that
// instant, so its week is drafted by the next start's catch-up or the Sunday
// after; Egypt (the deployment) is east of UTC. A zone-aware schedule if that changes.
Schedule::job(new GenerateWeeklyReportsJob, QueueName::Maintenance->value)->weeklyOn(0, '1:00');

// J-06 submit_unreviewed_weekly_reports (§15, D-136, D-137). Hourly, onto `maintenance`,
// with its catch-up (`schedule:catch-up`), after J-05 on start. The window
// is the setting's, read inside the job; hourly so a draft past it goes out within the hour.
Schedule::job(new SubmitUnreviewedWeeklyReportsJob, QueueName::Maintenance->value)->hourly();

// J-07 generate_monthly_report (§15, D-140, Module 13 slice 8 · 8.1). J-05's shape,
// onto `maintenance`, with its catch-up (`schedule:catch-up`), after J-06 on start.
// On the 1st, 01:00 UTC (the owner declined a local-time schedule) — the company's
// day and month are read inside the job; J-05's ponytail on zones west of UTC holds here too.
Schedule::job(new GenerateMonthlyReportsJob, QueueName::Maintenance->value)->monthlyOn(1, '1:00');

// J-08 archive_old_reports (§15, §11.4, D-127, Module 13 slice 7 · 7.4). J-04's shape,
// onto `maintenance`, daily at 00:00 UTC, with its catch-up (`schedule:catch-up`), last
// on start. The archive age is the `limits.report_archive_months` setting, read inside the job.
Schedule::job(new ArchiveOldReportsJob, QueueName::Maintenance->value)->daily();

// J-09 refresh_dashboard_aggregates (§15, D-146, Module 14 · 2.1b), onto `maintenance`
// (§15.1: aggregates): hourly over the days that changed, and in full nightly at
// 00:30 UTC. No catch-up entry — §15 marks it "❌ recomputes": the next run does.
Schedule::job(new RefreshDashboardAggregatesJob, QueueName::Maintenance->value)->hourly();
Schedule::job(new RefreshDashboardAggregatesJob(full: true), QueueName::Maintenance->value)->dailyAt('00:30');

// The startup catch-up (D-55, ST-05) for every job above that has one, run once by
// the `scheduler` service before `schedule:work`. It runs the schedule's own entry,
// so the queue is named once, and fails loudly on a missing entry rather than
// skipping it in silence. J-02 is deliberately absent (its comment above).
Artisan::command('schedule:catch-up', function (Illuminate\Console\Scheduling\Schedule $schedule): int {
    $jobs = [
        'J-01' => ExpireQuotationsJob::class,
        'J-04' => MarkMissedDailyReportsJob::class,
        'J-05' => GenerateWeeklyReportsJob::class,
        'J-06' => SubmitUnreviewedWeeklyReportsJob::class,
        'J-07' => GenerateMonthlyReportsJob::class,
        'J-08' => ArchiveOldReportsJob::class,
    ];

    foreach ($jobs as $code => $job) {
        $event = collect($schedule->events())->first(fn ($event): bool => $event->description === $job);

        if ($event === null) {
            $this->error("$code is not scheduled.");

            return Command::FAILURE;
        }

        $event->run($this->laravel);
        $this->info("$code dispatched.");
    }

    return Command::SUCCESS;
})->purpose('Run the startup catch-up of J-01, J-04, J-05, J-06, J-07 and J-08 (D-55, ST-05)');
