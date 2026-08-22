<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * The default until D-38 can actually be answered.
 *
 * Identity and dynamic RBAC are Module 1; deals, supplier quotations, purchase
 * orders and reports are Modules 5, 6, 10 and 13. Nothing in the system can say
 * today whether a person may view a deal, so nothing here pretends to.
 *
 * It denies rather than allows because the two defaults fail in opposite
 * directions: a denial is a feature that visibly does not work yet and gets
 * fixed, while a grant is an open endpoint that looks finished.
 */
final readonly class DenyAllAttachmentPermission implements AttachmentPermissionInterface
{
    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        return false;
    }
}
