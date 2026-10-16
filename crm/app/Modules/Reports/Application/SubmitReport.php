<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Contracts\ReportSubmitted;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Draft (or Returned, as the next version) → Submitted (§11.4): the frozen copy (§11.3, the acceptance criterion
 * "unchanged even if the data changes"; with `D-131`'s follow-ups and notes, read under the report's row lock) and the chosen recipients (`D-127`,
 * `D-129`), in one transaction under the token. `ReportSubmitted` follows the commit (`D-132`), so a
 * refused or rolled-back submit announces nothing.
 */
final readonly class SubmitReport
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private DailyActivity $activity,
        private ShowReport $show,
        private RecipientCandidates $candidates,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private Dispatcher $events,
    ) {}

    /** @param list<string> $recipientIds */
    public function submit(string $reportId, array $recipientIds, ?string $ifMatch, string $actorId): void
    {
        $version = $this->connection->transaction(function () use ($reportId, $recipientIds, $ifMatch, $actorId): int {
            $before = $this->access->open($reportId, $ifMatch, $actorId);

            if (! $before->isEditable()) {
                throw ReportWriteRefused::notDraft();
            }

            if (array_diff($recipientIds, $this->candidates->idsFor($before)) !== []) {
                throw ReportWriteRefused::recipientNotAllowed();
            }

            // `D-131`: the follow-ups with their customer's name as it is now, and the notes — no row ids.
            $snapshot = [
                'follow_ups' => array_map(static fn (array $row): array => array_diff_key($row, ['id' => true]), $this->show->followUpsOf($reportId)),
                'notes' => array_map(static fn (array $note): array => ['body' => $note['body']], $this->reports->rowsOf('note', $reportId)),
                'activity' => $this->activity->of($before->authorId, $before->periodStart),
            ];

            if (! $this->reports->submit($reportId, $before->versionToken, $snapshot, $recipientIds, $actorId)) {
                throw ReportWriteRefused::staleVersion(ReportEtag::of($before));
            }

            $this->audit->record(AuditEvent::of('REPORT_SUBMITTED'), 'report', $reportId, ['status' => $before->status], [
                'status' => 'submitted', 'recipient_ids' => $recipientIds,
            ]);

            return $before->submittedVersions + 1;
        });

        $this->events->dispatch(new ReportSubmitted($reportId, $version));
    }
}
