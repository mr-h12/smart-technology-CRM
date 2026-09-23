<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Access;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Quotations\Application\Listing\ReadPurchaseOrders;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderNotFound;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * `D-38` for a purchase order's file (Module 10 · 2.3): whoever may read the
 * order may read its file — `quotation.view`, scoped through the quotation's
 * deal, the detail's own check (`ReadPurchaseOrders::reachable`). Asked at
 * request time by `GET /files/{id}/download` (`OpenAPI §8.3`).
 *
 * No parent guard: `ParentAwareAttachmentPermission` routes only
 * `purchase_order` links here, and the deals' copy of that guard is recorded
 * as inert debt.
 */
final readonly class PurchaseOrderAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(
        private AuthorizeAction $authorize,
        private ReadPurchaseOrders $orders,
    ) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        try {
            $this->orders->reachable($link->parentId, $this->authorize->decide($actorId, 'quotation', 'view')->scopeValues(), $actorId);
        } catch (PurchaseOrderNotFound) {
            return false;
        }

        return true;
    }
}
