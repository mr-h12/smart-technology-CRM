<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-04` (§15; `D-135`): after midnight, every daily draft of a past working
 * day (`D-125`) on or after the go-live date becomes Missed. While the go-live
 * date is empty nothing is marked (`D-127`). Idempotent (§15.1): only drafts
 * move. The audit carries no actor — a queued job has no request, so the
 * entry records `user_id` NULL, as `ExpireQuotations` does (`J-01`).
 */
final readonly class MarkMissedDailyReports
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private CompanyToday $today,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @return int how many reports were marked Missed */
    public function run(): int
    {
        $goLive = $this->today->goLiveDate();

        if ($goLive === null) {
            return 0;
        }

        $today = $this->today->date();

        return $this->connection->transaction(function () use ($goLive, $today): int {
            $ids = $this->reports->markMissed($goLive, $today);

            foreach ($ids as $id) {
                $this->audit->record(AuditEvent::of('REPORT_MISSED'), 'report', $id, ['status' => 'draft'], ['status' => 'missed']);
            }

            return count($ids);
        });
    }
}
