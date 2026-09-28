<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Access;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;

/**
 * §3.5's two PDF rows — *generate PDF* and *export/download PDF* — for one
 * quotation and one caller: the single answer behind the download (4.1), the
 * status read (4.2) and the generation (3.5).
 *
 * "Own" is the deal owner's (owner ruling 2026-09-11): a quotation has no owner
 * column, so the reach is `DealRowScope` against the owner `DealFactsInterface`
 * reports. `Asgn` (Procurement, Q2) and `Team` (the Team Leader on *generate*,
 * `D-a`) resolve to nothing, so they are `Unbacked`. A soft-deleted quotation
 * is absent (`DB-01`).
 *
 * In `Application`, not `Infrastructure`: `AuthorizeAction` is Identity's
 * Application-layer entry point, and `deptrac.layers.yaml` refuses
 * Infrastructure → Application — where `DealAttachmentPermission` landed for
 * the same measured reason.
 */
final readonly class QuotationPdfAccess
{
    public function __construct(
        private AuthorizeAction $authorize,
        private QuotationReaderInterface $quotations,
        private DealFactsInterface $deals,
    ) {}

    /**
     * @param  'generate_pdf'|'export_pdf'  $action
     */
    public function reach(string $action, string $quotationId, string $actorId): PdfReach
    {
        $scope = DealRowScope::resolve($this->authorize->decide($actorId, 'quotation', $action)->scopeValues(), $actorId);

        if ($scope->permitsNothing()) {
            return PdfReach::Unbacked;
        }

        $quotation = $this->quotations->find($quotationId);

        if ($quotation === null) {
            return PdfReach::Outside;
        }

        return $scope->unrestricted || $scope->reaches($this->deals->factsOf($quotation->dealId)?->ownerId)
            ? PdfReach::Reached
            : PdfReach::Outside;
    }
}
