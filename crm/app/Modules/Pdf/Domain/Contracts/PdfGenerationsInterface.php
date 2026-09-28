<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

use App\Modules\Pdf\Domain\Generation\PdfGeneration;

/**
 * `pdf_generations` (Point 3.3), read by `Pdf` alone. Soft-deleted rows are
 * absent (`DB-01`); "latest" is the newest request, a tie broken by the
 * time-ordered id (`D-61`).
 */
interface PdfGenerationsInterface
{
    /** The newest generation of one quotation, whatever its status; null when none was asked for. */
    public function latestFor(string $quotationId): ?PdfGeneration;

    /** The file of the newest `completed` one — the snapshot a download serves (Q4). */
    public function latestFileIdFor(string $quotationId): ?string;
}
