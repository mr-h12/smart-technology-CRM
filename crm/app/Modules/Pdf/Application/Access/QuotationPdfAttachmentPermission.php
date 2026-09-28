<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Access;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * Module 9, Point 4.1 — `D-38` for `AttachmentParent::Quotation`: a stored
 * customer PDF is read under §3.5's *export/download PDF* row, which is the
 * quotation's own permission for its files.
 *
 * "Own" on that row is the deal owner's (owner ruling 2026-09-11) — a quotation
 * has no owner column — so the reach is `DealRowScope`, the rule Module 5
 * already applies to deals, against the owner `DealFactsInterface` reports.
 * `Asgn` resolves to nothing, so Procurement fails closed (Q2); the Team
 * Leader's ✅ is `All` (`D-91`). A soft-deleted quotation is absent (`DB-01`).
 *
 * In `Application`, not `Infrastructure`: `AuthorizeAction` is Identity's
 * Application-layer entry point, and `deptrac.layers.yaml` refuses
 * Infrastructure → Application — where `DealAttachmentPermission` landed for
 * the same measured reason.
 */
final readonly class QuotationPdfAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(
        private AuthorizeAction $authorize,
        private QuotationReaderInterface $quotations,
        private DealFactsInterface $deals,
    ) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        if ($link->parent !== AttachmentParent::Quotation) {
            return false;
        }

        $scope = DealRowScope::resolve(
            $this->authorize->decide($actorId, 'quotation', 'export_pdf')->scopeValues(),
            $actorId,
        );

        if ($scope->permitsNothing()) {
            return false;
        }

        $quotation = $this->quotations->find($link->parentId);

        return $quotation !== null
            && ($scope->unrestricted || $scope->reaches($this->deals->factsOf($quotation->dealId)?->ownerId));
    }
}
