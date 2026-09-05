<?php

declare(strict_types=1);

namespace App\Modules\Storage\Application;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;

/**
 * `D-38` routed to whichever module owns the parent.
 *
 * ── The decision Module 5 deferred, taken by Module 6 Point 5.1 ────────────
 *
 * `DealAttachmentPermission`'s docblock left the choice open in writing:
 * "Whoever builds the second parent's permission decides then whether this
 * class grows a `match` or a composite replaces it." The `match` is refused,
 * because growing Deals' class to answer for supplier quotations would put
 * Module 6's rule — §3.6's scopes and `SupplierQuotationDirectoryInterface` —
 * inside Module 5. `CLAUDE.md` forbids that crossing in stronger terms than it
 * forbids a registry: "a cross-module need is an interface or a domain event,
 * never an edit next door."
 *
 * ── Why this is not the speculative abstraction Module 5 was right to refuse ──
 *
 * A registry for a set of one is an abstraction with no second case. This one
 * has two real implementations on the day it is written, in two modules that
 * may not see each other, and the map is built in `AppServiceProvider` — the
 * one place already outside every module boundary, and already where the
 * single binding it replaces lived.
 *
 * ── An unmapped parent is refused, not passed on ──────────────────────────
 *
 * Modules 10 and 13 are still `.gitkeep`, so `PurchaseOrder` and `Report` have
 * no entry and get `false`. That is `DenyAllAttachmentPermission`'s rule kept
 * rather than dropped, and `AttachmentPermissionInterface` gives the reason:
 * "A default that denies is a feature that does not work yet; a default that
 * grants is a hole nobody notices."
 *
 * This class holds no rule of its own. It cannot: `Storage` may not read
 * another module's tables, and the permission matrix is Module 1's database.
 */
final readonly class ParentAwareAttachmentPermission implements AttachmentPermissionInterface
{
    /** @param  array<string, AttachmentPermissionInterface>  $byParent  keyed by {@see AttachmentParent}'s value */
    public function __construct(private array $byParent) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        $owner = $this->byParent[$link->parent->value] ?? null;

        return $owner instanceof AttachmentPermissionInterface
            && $owner->mayView($link, $actorId);
    }
}
