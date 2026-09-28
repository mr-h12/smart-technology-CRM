<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Access;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * Module 9, Point 4.1 — `D-38` for `AttachmentParent::Quotation`: a stored
 * customer PDF is read under §3.5's *export/download PDF* row, which is the
 * quotation's own permission for its files.
 *
 * The reach is `QuotationPdfAccess`'s, the one answer 4.2's status read gives
 * too. Every refusal is the same `false` — `Unbacked` and `Outside` alike —
 * because the download answers each with a 404 (`OpenAPI §8.3`).
 */
final readonly class QuotationPdfAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(private QuotationPdfAccess $access) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        return $link->parent === AttachmentParent::Quotation
            && $this->access->reach('export_pdf', $link->parentId, $actorId) === PdfReach::Reached;
    }
}
