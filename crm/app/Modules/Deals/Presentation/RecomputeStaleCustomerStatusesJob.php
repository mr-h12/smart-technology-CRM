<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Deals\Application\CustomerStatus\RecomputeStaleCustomerStatuses;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * `J-02`'s nightly entry point — `EnsureAuditPartitionsCommand`'s "thin, and
 * nothing else" shape, on the same reasoning, but a queued job rather than a
 * scheduler-run command.
 *
 * ── Queued, not scheduler-run like `J-15` ───────────────────────────────────
 *
 * `§15` reserves the scheduler-not-queue shape for exactly one documented
 * exception: `J-15`, because a queued audit-partition job would have "no
 * Queue Monitor to be seen failing in" while `audit_log` quietly ran out of
 * months — a severe, silent failure mode. A missed customer-status
 * recompute has no such urgency: the worst case is one night's delay before
 * the next run (or the next deal-write event) corrects it, on this class's
 * own idempotent-and-clock-driven reasoning. So this follows `§15`'s
 * *default* rule instead — queued, onto `maintenance` (`QueueName::Maintenance`,
 * "Cleanup · indexing · aggregates") — retried by the worker's own
 * `--tries=3`, the same durability every other queued job in this stack
 * gets, rather than carved out as a second exception nothing here justifies.
 */
final class RecomputeStaleCustomerStatusesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(RecomputeStaleCustomerStatuses $recompute): void
    {
        $count = $recompute->run();

        Log::info("J-02: recomputed {$count} customer(s) with an active deal.");
    }
}
