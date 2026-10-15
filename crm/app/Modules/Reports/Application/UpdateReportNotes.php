<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Database\ConnectionInterface;

/** §11.3's free-text notes on a draft, under the token (`DB-12`), audited with old and new (`AUD-02`). */
final readonly class UpdateReportNotes
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    public function update(string $reportId, ?string $notes, ?string $ifMatch, string $actorId): void
    {
        $this->connection->transaction(function () use ($reportId, $notes, $ifMatch, $actorId): void {
            $before = $this->access->open($reportId, $ifMatch, $actorId);

            if (! $before->isDraft()) {
                throw ReportWriteRefused::notDraft();
            }

            if (! $this->reports->updateNotes($reportId, $before->versionToken, $notes, $actorId)) {
                throw ReportWriteRefused::staleVersion(ReportEtag::of($before));
            }

            $this->audit->record(AuditEvent::of('REPORT_UPDATED'), 'report', $reportId, ['notes' => $before->notes], ['notes' => $notes]);
        });
    }
}
