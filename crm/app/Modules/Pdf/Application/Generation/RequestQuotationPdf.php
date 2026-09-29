<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Generation;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Pdf\Application\Access\PdfReach;
use App\Modules\Pdf\Application\Access\QuotationPdfAccess;
use App\Modules\Pdf\Application\CustomerQuotationViewMapper;
use App\Modules\Pdf\Domain\Contracts\PdfGenerationsInterface;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use App\Modules\Pdf\Domain\View\CustomerViewIncomplete;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use Illuminate\Database\ConnectionInterface;

/**
 * Module 9, Point 3.5 — a request to render one quotation's customer PDF,
 * under §3.5's *generate PDF* row, whose "own" is the deal owner's (owner
 * ruling 2026-09-11) through `QuotationPdfAccess`.
 *
 * The view is mapped here, when the button is pressed (Q10), so a quotation
 * that cannot be printed is refused now rather than failing in the queue. The
 * generation and its `QUOTATION_PDF_REQUESTED` entry are one transaction, with
 * the caller as the actor: a queued job has no request to take one from.
 */
final readonly class RequestQuotationPdf
{
    public function __construct(
        private QuotationPdfAccess $access,
        private CustomerQuotationViewMapper $mapper,
        private PdfGenerationsInterface $generations,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  'ar'|'en'  $locale  the document's language (Q15)
     * @return array{generationId: string, view: CustomerQuotationView}
     *
     * @throws AuthorizationRefused when the caller's scope reaches no quotation (Procurement's `Asgn`, Q2; the Team Leader's `Team`, `D-a`)
     * @throws QuotationNotFound when the quotation is absent or outside the caller's reach
     * @throws CustomerViewIncomplete when a fact the customer's document needs is missing
     */
    public function request(string $quotationId, string $locale, string $actorId): array
    {
        $reach = $this->access->reach('generate_pdf', $quotationId, $actorId);

        if ($reach === PdfReach::Unbacked) {
            throw AuthorizationRefused::of('quotation', 'generate_pdf');
        }

        if ($reach === PdfReach::Outside) {
            throw QuotationNotFound::of($quotationId);
        }

        $view = $this->mapper->forQuotation($quotationId);

        $generationId = $this->connection->transaction(function () use ($quotationId, $locale, $actorId): string {
            $generationId = $this->generations->queue($quotationId, $locale, $actorId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_PDF_REQUESTED'),
                'quotation',
                $quotationId,
                null,
                ['job_id' => $generationId, 'locale' => $locale],
            );

            return $generationId;
        });

        return ['generationId' => $generationId, 'view' => $view];
    }
}
