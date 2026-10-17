<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

use App\Modules\Pdf\Domain\Generation\ReportPdfGeneration;

/**
 * `report_pdf_generations` (`D-132`), read and written by `Pdf` alone — the
 * report's twin of `PdfGenerationsInterface`. Soft-deleted rows are absent
 * (`DB-01`); none of the job's writes touches a generation already final.
 */
interface ReportPdfGenerationsInterface
{
    /** A new `queued` row for one submitted version; null when that version already has one (the unique key). */
    public function queue(string $reportId, int $version, string $actorId): ?string;

    public function find(string $id): ?ReportPdfGeneration;

    public function forVersion(string $reportId, int $version): ?ReportPdfGeneration;

    public function recordAttempt(string $id, int $attempt): void;

    /** The render is stored; its status stays `queued` until the scan answers. */
    public function attachFile(string $id, string $fileId): void;

    public function complete(string $id): void;

    public function fail(string $id, string $reason): void;

    /**
     * `failed` → `queued` for a manual retry, by `$actorId`; the attached file stays. A conditional write on
     * `failed`, so of two concurrent retries only one returns true.
     */
    public function requeue(string $id, string $actorId): bool;
}
