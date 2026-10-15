<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * The recipient's side of §11.4 (`D-127`; the owner, 2026-10-16): Viewed on
 * the first open, then Acknowledge or Return with a reason under the token —
 * each recorded on the newest version row and audited (`AUD-01`).
 */
final readonly class ReceiveReport
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** Submitted → Viewed on a recipient's first open; any later open changes nothing. */
    public function view(string $reportId, string $actorId): void
    {
        $this->connection->transaction(function () use ($reportId, $actorId): void {
            $report = $this->access->reachReceived($reportId, $actorId);

            // A recipient who loses the race to another's first open has nothing to record.
            if ($report->status === 'submitted' && $this->reports->receive($reportId, $report->versionToken, 'viewed', null, $actorId)) {
                $this->audit->record(AuditEvent::of('REPORT_VIEWED'), 'report', $reportId, ['status' => 'submitted'], ['status' => 'viewed']);
            }
        });
    }

    public function acknowledge(string $reportId, ?string $ifMatch, string $actorId): void
    {
        $this->answer($reportId, $ifMatch, $actorId, 'acknowledged', null, 'REPORT_ACKNOWLEDGED');
    }

    /** §11.4: Returned carries a mandatory reason (the Form Request's). */
    public function returnToAuthor(string $reportId, string $reason, ?string $ifMatch, string $actorId): void
    {
        $this->answer($reportId, $ifMatch, $actorId, 'returned', $reason, 'REPORT_RETURNED');
    }

    private function answer(string $reportId, ?string $ifMatch, string $actorId, string $status, ?string $reason, string $event): void
    {
        $this->connection->transaction(function () use ($reportId, $ifMatch, $actorId, $status, $reason, $event): void {
            $before = $this->access->openReceived($reportId, $ifMatch, $actorId);

            if (! $before->awaitsAnswer()) {
                throw ReportWriteRefused::notAwaitingAnswer();
            }

            if (! $this->reports->receive($reportId, $before->versionToken, $status, $reason, $actorId)) {
                throw ReportWriteRefused::staleVersion(ReportEtag::of($before));
            }

            $this->audit->record(AuditEvent::of($event), 'report', $reportId, ['status' => $before->status], [
                'status' => $status, ...($reason === null ? [] : ['return_reason' => $reason]),
            ]);
        });
    }
}
