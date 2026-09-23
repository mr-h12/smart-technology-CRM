<?php

declare(strict_types=1);

use App\Modules\Audit\Presentation\EnsureAuditPartitionsCommand;
use App\Modules\Deals\Presentation\RecomputeStaleCustomerStatusesJob;
use App\Modules\Quotations\Presentation\ExpireQuotationsJob;
use App\Support\Queue\QueueName;
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
// `scheduler` service runs `quotations:expire` once before `schedule:work`.
// At 00:00 UTC — the company's day is read inside the job; local-time
// scheduling was declined by the owner (2026-09-23).
Schedule::job(new ExpireQuotationsJob, QueueName::Maintenance->value)->daily();
