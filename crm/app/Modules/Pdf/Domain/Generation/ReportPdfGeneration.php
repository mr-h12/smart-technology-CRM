<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Generation;

use DateTimeImmutable;

/**
 * One `report_pdf_generations` row (`D-132`, spec §4.2): the stored PDF of one
 * submitted version of a report. `fileId` is set once the render is stored,
 * `finishedAt` once it completed or gave up; `requestedBy` is the report's
 * author, and the job acts for them — `PdfGeneration`'s shape for a report.
 */
final readonly class ReportPdfGeneration
{
    public function __construct(
        public string $id,
        public string $reportId,
        public int $version,
        public PdfGenerationStatus $status,
        public ?string $fileId,
        public ?string $requestedBy,
        public ?DateTimeImmutable $finishedAt,
        public ?string $failureReason,
    ) {}
}
