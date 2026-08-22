<?php

declare(strict_types=1);

use App\Modules\Audit\Presentation\EnsureAuditPartitionsCommand;
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
