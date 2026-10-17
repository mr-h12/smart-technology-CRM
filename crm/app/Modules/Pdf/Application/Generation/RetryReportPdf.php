<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Generation;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-132`, point 7.2 — a manual retry of a report version's stored PDF. The people who
 * read it may retry it (the author or a live recipient, `SubmittedReportsInterface::mayOpen()`);
 * only a `failed` generation goes back to `queued`, and the reset and its `REPORT_PDF_RETRIED`
 * entry are one transaction (`AUD-01`). The caller pushes the job once this has committed.
 */
final readonly class RetryReportPdf
{
    public function __construct(
        private SubmittedReportsInterface $reports,
        private ReportPdfGenerationsInterface $generations,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @return string the generation's id, for the job
     *
     * @throws ReportNotFound when the caller is outside the rule, or the version has no generation
     * @throws ReportWriteRefused when the generation is not `failed` (or another retry took it first)
     */
    public function retry(string $reportId, int $version, string $actorId): string
    {
        $generation = $this->reports->mayOpen($reportId, $actorId) ? $this->generations->forVersion($reportId, $version) : null;

        if ($generation === null) {
            throw ReportNotFound::of($reportId);
        }

        $this->connection->transaction(function () use ($generation, $reportId, $version, $actorId): void {
            if (! $this->generations->requeue($generation->id, $actorId)) {
                throw ReportWriteRefused::pdfNotRetryable();
            }

            $this->audit->record(
                AuditEvent::of('REPORT_PDF_RETRIED'),
                'report',
                $reportId,
                ['status' => PdfGenerationStatus::Failed->value],
                ['status' => PdfGenerationStatus::Queued->value, 'version' => $version],
            );
        });

        return $generation->id;
    }
}
