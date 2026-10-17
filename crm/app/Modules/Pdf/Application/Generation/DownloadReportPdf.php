<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Generation;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Storage\Application\DownloadFile;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\StoredFile;

/**
 * `D-132`, point 7.2 — the stored PDF of a submitted version, handed to its author or a live
 * recipient with one `REPORT_PDF_DOWNLOADED` entry per download (the shared files route audits
 * nothing). Only a `completed` generation has a file to give; anything else is the report's
 * 404, and so is a file Storage will not serve (`D-38`, `SEC-15`). The entry is written once
 * the file is open, so a download that did not happen leaves none.
 */
final readonly class DownloadReportPdf
{
    public function __construct(
        private SubmittedReportsInterface $reports,
        private ReportPdfGenerationsInterface $generations,
        private DownloadFile $download,
        private StorageServiceInterface $storage,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @return array{file: StoredFile, stream: resource, name: string} `name` is `<code>-v<version>.pdf`
     *
     * @throws ReportNotFound
     */
    public function open(string $reportId, int $version, string $actorId): array
    {
        $generation = $this->reports->mayOpen($reportId, $actorId) ? $this->generations->forVersion($reportId, $version) : null;
        $report = $generation === null ? null : $this->reports->version($reportId, $version);
        $file = $generation?->status === PdfGenerationStatus::Completed && $generation->fileId !== null
            ? $this->download->forActor($generation->fileId, $actorId)
            : null;

        if ($report === null || $file === null) {
            throw ReportNotFound::of($reportId);
        }

        $stream = $this->storage->readStream($file->path);

        $this->audit->record(AuditEvent::of('REPORT_PDF_DOWNLOADED'), 'report', $reportId, null, ['version' => $version, 'file_id' => $file->id]);

        return ['file' => $file, 'stream' => $stream, 'name' => "{$report['code']}-v{$version}.pdf"];
    }
}
