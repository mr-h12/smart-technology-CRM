<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Access;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;

/**
 * `D-38` for the second of the four parents — "attachment permission =
 * permission on the parent entity", where the parent is §7.2's offer and the
 * attachment is its `pdf_file` row ("Scan or PDF of the offer").
 *
 * ── This is the only thing guarding the download ──────────────────────────
 *
 * `GET /api/v1/files/{file}/download` carries `auth` and nothing else, on
 * purpose: the route is parent-agnostic and cannot know which permission to
 * name. So there is no middleware behind this method — `mayView` is the whole
 * check, and a `true` returned carelessly here is somebody else's document.
 *
 * ── `Application`, not `Infrastructure` ───────────────────────────────────
 *
 * `DealAttachmentPermission`'s reason, unchanged: `deptrac.layers.yaml` refuses
 * Infrastructure depending on any Application layer, and answering this
 * question means calling `AuthorizeAction`, Identity's Application-layer entry
 * point for "what does this actor hold" outside a route's own middleware.
 *
 * ── No `RowScope`, unlike Deals ───────────────────────────────────────────
 *
 * `DealAttachmentPermission` resolves a `DealRowScope` because §3.4 gives deals
 * five different reaches. §3.6 gives this resource one — `All` for every role
 * in the `view` row, under "a shared screen — not restricted by ownership" — so
 * the question is whether the caller holds the grant at all, and then whether
 * the offer is still there. A scope here would be the "parameter every caller
 * passes the same value for" that `SupplierQuotationDirectoryInterface` already
 * refuses for the same reason.
 *
 * `view`, not `upload_attachment`: this method answers "may see". §3.6 gives
 * the CEO `view` as `All` and no `upload_attachment` grant at all, and reading
 * an attachment they cannot add is the same asymmetry Points 2.2 and 2.3 proved
 * on the offer itself.
 *
 * ── No parent guard, and that was measured rather than reasoned ───────────
 *
 * `DealAttachmentPermission` opens with `if ($link->parent !== Deal) return
 * false;` and this class was written with the mirror of it, defended in a
 * comment as the catch for a mis-keyed composite map. **The probe said
 * otherwise:** deleting the branch reddened nothing, because a foreign parent's
 * id is not a supplier quotation's id, so `find()` returns `null` and the
 * answer is already `false`. A branch with no observable effect is the dead
 * code `CLAUDE.md`'s waste audit names, so it was removed inside the point that
 * created it. The refusal is still asserted — it is behaviour this class owes —
 * it simply is not a branch.
 *
 * **`find()` and not a lighter `exists()`**, though this needs only presence:
 * the contract has no such method, and adding one for a single caller is the
 * interface method nobody else would use. The stated ceiling is that this
 * loads the offer's line items to answer a yes/no; if attachment downloads ever
 * become hot, that is the thing to measure.
 */
final readonly class SupplierQuotationAttachmentPermission implements AttachmentPermissionInterface
{
    public function __construct(
        private AuthorizeAction $authorize,
        private SupplierQuotationDirectoryInterface $quotations,
    ) {}

    public function mayView(AttachmentLink $link, string $actorId): bool
    {
        if (! $this->authorize->decide($actorId, 'supplier_quotation', 'view')->allows(Scope::All)) {
            return false;
        }

        // `DB-01`: a soft-deleted offer is not there, so neither is its
        // attachment — `find()` already excludes it.
        return $this->quotations->find($link->parentId) !== null;
    }
}
