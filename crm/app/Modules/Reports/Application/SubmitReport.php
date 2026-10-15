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
 * Draft → Submitted (§11.4): the frozen copy (§11.3, the acceptance criterion
 * "unchanged even if the data changes") and the chosen recipients (`D-127`,
 * `D-129`), in one transaction under the token.
 */
final readonly class SubmitReport
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private DailyActivity $activity,
        private RecipientCandidates $candidates,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param list<string> $recipientIds */
    public function submit(string $reportId, array $recipientIds, ?string $ifMatch, string $actorId): void
    {
        $this->connection->transaction(function () use ($reportId, $recipientIds, $ifMatch, $actorId): void {
            $before = $this->access->open($reportId, $ifMatch, $actorId);

            if (! $before->isDraft()) {
                throw ReportWriteRefused::notDraft();
            }

            if (array_diff($recipientIds, $this->candidates->idsFor($before)) !== []) {
                throw ReportWriteRefused::recipientNotAllowed();
            }

            $snapshot = ['notes' => $before->notes, 'activity' => $this->activity->of($before->authorId, $before->periodStart)];

            if (! $this->reports->submit($reportId, $before->versionToken, $snapshot, $recipientIds, $actorId)) {
                throw ReportWriteRefused::staleVersion(ReportEtag::of($before));
            }

            $this->audit->record(AuditEvent::of('REPORT_SUBMITTED'), 'report', $reportId, ['status' => 'draft'], [
                'status' => 'submitted', 'recipient_ids' => $recipientIds,
            ]);
        });
    }
}
