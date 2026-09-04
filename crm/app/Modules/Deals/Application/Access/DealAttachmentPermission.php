<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Access;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * D-38 for one of the four parents `AttachmentPermissionInterface` names —
 * the crossing `AppServiceProvider`'s own comment on `DenyAllAttachmentPermission`
 * predicted: "Replacing this line is how those modules switch the download
 * endpoint on." Deals is the first of the four to exist, so this replaces it,
 * and still denies `SupplierQuotation`, `PurchaseOrder` and `Report` outright
 * — Modules 6, 10 and 13 are still `.gitkeep`, and there is nothing here that
 * could answer for a parent Storage cannot yet name a table for.
 *
 * **`Application`, not `Infrastructure`, despite implementing an Infrastructure-
 * shaped contract.** `deptrac.layers.yaml` refuses Infrastructure depending on
 * any Application layer, own module's or foreign, and answering `mayView`
 * means calling `AuthorizeAction` — Identity's Application-layer entry point
 * for "what does this actor hold" outside a route's own middleware. This class
 * orchestrates a decision the way `AttachDealDocument` does; it only looks
 * like an adapter because the interface it fulfils happens to live in another
 * module's Domain.
 *
 * `deal.view`, not `deal.edit`: this method answers "may see", the same
 * question §3.4's `view timeline` row asks, and its scope (`All · Team · Out
 * · Own · Own · Asgn · All`) is the one that includes the CEO — a role the
 * `edit` row excludes but §3.4 still grants deal visibility to.
 *
 * A composite across all four parents was not built here: only one has a real
 * implementation, and a registry for a set of one is the abstraction
 * `CLAUDE.md`'s "do not introduce speculative abstractions" already refuses.
 * Whoever builds the second parent's permission decides then whether this
 * class grows a `match` or a composite replaces it.
 *
 * **Decided, 2026-09-04, by Module 6 Point 5.1: a composite.** This class was
 * not grown, because a `match` here would put §3.6's rule and
 * `SupplierQuotationDirectoryInterface` inside Module 5.
 * `ParentAwareAttachmentPermission` now holds the binding and routes by parent;
 * this class keeps answering for `Deal` alone.
 *
 * ⚠️ While taking that decision, Point 5.1 measured this class's own
 * `$link->parent !== Deal` guard and found it inert: a foreign parent's id is
 * not a deal's id, so `find()` returns `null` and the answer is already
 * `false`. Module 6's mirror of it was deleted for that reason. This one is
 * left alone — it belongs to Module 5, and a Module 6 point does not edit
 * another module's code on a finding it made in passing. Recorded in
 * `CHECKLIST.md` instead.
 */
final readonly class DealAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DealDirectoryInterface $deals,
    ) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        if ($link->parent !== AttachmentParent::Deal) {
            return false;
        }

        $decision = $this->authorize->decide($actorId, 'deal', 'view');
        $scope = DealRowScope::resolve($decision->scopeValues(), $actorId);

        return $this->deals->find($link->parentId, $scope) !== null;
    }
}
