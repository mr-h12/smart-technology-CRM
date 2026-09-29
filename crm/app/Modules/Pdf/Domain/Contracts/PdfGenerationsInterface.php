<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

use App\Modules\Pdf\Domain\Generation\PdfGeneration;

/**
 * `pdf_generations` (Point 3.3), read and written by `Pdf` alone. Soft-deleted
 * rows are absent (`DB-01`); "latest" is the newest request, a tie broken by
 * the time-ordered id (`D-61`). The writes are the job's (3.4): each one acts
 * for whoever asked, and none of them touches a generation already final.
 */
interface PdfGenerationsInterface
{
    /** The newest generation of one quotation, whatever its status; null when none was asked for. */
    public function latestFor(string $quotationId): ?PdfGeneration;

    /** The file of the newest `completed` one — the snapshot a download serves (Q4). */
    public function latestFileIdFor(string $quotationId): ?string;

    public function find(string $id): ?PdfGeneration;

    /** Q3's record of how many tries a render took — the worker's count, 1-based. */
    public function recordAttempt(string $id, int $attempt): void;

    /** The render is stored; its status stays `queued` until the scan answers (Q13). */
    public function attachFile(string $id, string $fileId): void;

    public function complete(string $id): void;

    public function fail(string $id, string $reason): void;
}
