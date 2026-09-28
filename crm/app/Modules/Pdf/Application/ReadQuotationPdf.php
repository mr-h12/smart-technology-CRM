<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Pdf\Application\Access\PdfReach;
use App\Modules\Pdf\Application\Access\QuotationPdfAccess;
use App\Modules\Pdf\Domain\Contracts\PdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGeneration;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;

/**
 * Module 9, Point 4.2 — what `OpenAPI §4.3` leaves to "the module contract":
 * the state of one quotation's PDF, under §3.5's *export/download PDF* row.
 *
 * Two answers, because they differ while a new render is queued or has failed:
 * the newest generation (its `job_id` and status) and the newest completed
 * file (the one a download serves, Q4). Earlier snapshots stay stored
 * (`DB-01`) and are not listed.
 */
final readonly class ReadQuotationPdf
{
    public function __construct(
        private QuotationPdfAccess $access,
        private PdfGenerationsInterface $generations,
    ) {}

    /**
     * @return array{generation: ?PdfGeneration, latestFileId: ?string}
     *
     * @throws AuthorizationRefused when the caller's scope reaches no quotation (Procurement's `Asgn`, Q2)
     * @throws QuotationNotFound when the quotation is absent or outside the caller's reach
     */
    public function of(string $quotationId, string $actorId): array
    {
        $reach = $this->access->reach('export_pdf', $quotationId, $actorId);

        if ($reach === PdfReach::Unbacked) {
            throw AuthorizationRefused::of('quotation', 'export_pdf');
        }

        if ($reach === PdfReach::Outside) {
            throw QuotationNotFound::of($quotationId);
        }

        return [
            'generation' => $this->generations->latestFor($quotationId),
            'latestFileId' => $this->generations->latestFileIdFor($quotationId),
        ];
    }
}
