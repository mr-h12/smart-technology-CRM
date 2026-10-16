<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Access;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * `D-38` for `AttachmentParent::Report` (`D-132`, spec §4.3): a report's stored
 * PDF is read with `report.export` **and** as the report's author or a live
 * recipient — the status route's rule, where the route checks the first half.
 * Every refusal is the same `false`, which the download answers with a 404.
 * ponytail: `Team` / `Out` narrow nothing until teams exist (`D-129`).
 */
final readonly class ReportPdfAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(
        private AuthorizeAction $authorize,
        private SubmittedReportsInterface $reports,
    ) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        return $link->parent === AttachmentParent::Report
            && $this->authorize->decide($actorId, 'report', 'export')->granted
            && $this->reports->mayOpen($link->parentId, $actorId);
    }
}
