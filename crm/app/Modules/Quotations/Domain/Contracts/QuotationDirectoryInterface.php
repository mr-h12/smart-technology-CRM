<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;

/**
 * The `quotations` table as §6's screen needs to write it —
 * `SupplierQuotationDirectoryInterface`'s shape (Module 6 Point 1.3), with one
 * difference that matters later.
 *
 * ── One method, because an unimplemented promise is not a contract ─────────
 *
 * Module 6 published `create()` alone at its Point 1.3 on the rule that "an
 * interface ahead of its implementation is a promise the next point must keep
 * in a shape it did not choose", and `find()`, `update()` and `list()` each
 * arrived with the point that implemented them. The same applies here: the read
 * is Step 3, the transitions are Step 4 and the list is Step 5.
 *
 * ── A scope parameter is coming, unlike Module 6 ───────────────────────────
 *
 * §3.6 gives supplier quotations exactly one scope — `All`, "a shared screen" —
 * so that interface refuses a `RowScope` parameter outright. §3.5 does **not**:
 * `quotation.view` is granted `own`, `team`, `asgn` and `all` to different
 * roles, so `SEC-08` will make every reader here take a scope. `create()` does
 * not, because creating a row is not reading one, and no module's create takes
 * one. This is recorded so the absence is read as a boundary, not as a
 * precedent.
 *
 * What "own" means for a quotation — `created_by`, or the deal's owner — is
 * still open and is Step 3's decision (`CHECKLIST.md`). No column is needed
 * either way, which is why Point 1.1 left the table without a scope index.
 */
interface QuotationDirectoryInterface
{
    /**
     * Allocates the `QT-YYYY-NNNN` code internally (§4.7, via
     * `document_sequences`) — the caller supplies a draft, not a code, so it
     * cannot collide with another writer's allocation.
     *
     * `$actorId` is `DB-02`'s `created_by`/`updated_by`, and it is a parameter
     * rather than a draft field because it is a fact about who was
     * authenticated, never about what was submitted.
     *
     * Writes the header and, from the draft's `withLines()`, Points 1.3 and 1.4's
     * `quotation_items` and `quotation_additional_items` (Step 3 Point 3.2). It
     * does **not** open a transaction: `CreateQuotation` (Point 3.3) wraps this
     * write and the `QUOTATION_CREATED` audit row in one commit (`DB-11`),
     * alongside the pricing engine that produces the totals and line figures
     * this stores — the same division `CreateSupplierQuotation` already draws.
     */
    public function create(QuotationDraft $draft, string $actorId): QuotationSummary;

    /**
     * The header and both child tables, or null when no live row has this id
     * (`DB-01`: a soft-deleted quotation is absent).
     *
     * **Unscoped, unlike `EloquentDealDirectory::find()`**, and on purpose:
     * §3.5's "own" is the *deal's* `owner_id` (owner ruling 2026-09-11), a
     * column in another module's table. Filtering here would mean a subquery
     * on `deals`, which is the cross-module database access `CLAUDE.md`
     * forbids. `ShowQuotation` (Point 3.5) asks `DealFactsInterface` — the
     * seam Point 3.4 built for the create — and applies `QuotationRowScope`
     * to the answer, so the boundary is crossed by the interface, not the SQL.
     */
    public function find(string $quotationId): ?QuotationDetail;

    /**
     * `DB-12`'s guarded write: the header is updated **only where**
     * `version_token = $expectedToken`, and the same statement advances the
     * token. Returns false when no row matched — the token moved between the
     * caller's read and this write (a concurrent edit), and the caller answers
     * `409` (`API-12`). Nothing else distinguishes "stale" from "absent"
     * here; the caller has already read the row inside the same transaction.
     *
     * Replaces both child tables from the draft's `withLines()`: the old rows
     * are soft-deleted (`DB-01`), never removed, and the new ones written as
     * `create()` writes them. The parent's token covers the children, which is
     * the obligation Point 1.1's note left to this write — a child-only change
     * still moves the token, because the caller always goes through here.
     */
    public function update(string $quotationId, QuotationDraft $draft, int $expectedToken, string $actorId): bool;

    /**
     * §6.4's first arrow, `Draft ──submit──► Pending`, under the same guard as
     * `update()`: `status` moves to `pending` and `submitted_at` is stamped
     * **only where** `version_token = $expectedToken`, and the statement
     * advances the token. False when no row matched — stale, `409`. The
     * caller has already checked the transition against
     * `QuotationStatusTransition`; this writes, it does not decide.
     *
     * ponytail: one status, one stamp. Module 8's `approve()` is the second
     * copy; the return (8 · 1.2) is the third and folds all three into
     * `moveStatus(id, to, token, actor, attributes)`.
     */
    public function submit(string $quotationId, int $expectedToken, string $actorId): bool;

    /**
     * §6.4's `Pending ──approve──► Approved` under `submit()`'s guard:
     * `status` moves to `approved` and `is_self_approved` is written as the
     * caller decided it (§6.5, `D-50`) **only where** `version_token =
     * $expectedToken`. False when no row matched — stale, `409`.
     */
    public function approve(string $quotationId, int $expectedToken, string $actorId, bool $selfApproved): bool;

    /**
     * §6.3 / `D-08`'s "full copy" (Point 4.3): a new `quotations` row with
     * `parent_id = $parentId`, `version = parent.version + 1`, `status =
     * draft`, its own `QT-` code (`create()`'s allocator), every field of the
     * document the customer answered **verbatim** — captured `unit_cost`, FX
     * rate and rounding included — and both child tables re-inserted in
     * `line_no` order. The answer's own marks are not copied: `rejection_reason`,
     * `sent_at`, `submitted_at`, `is_self_approved` start empty and
     * `version_token` starts at 1, because the copy is a fresh draft of the
     * same document (§6.2's Versioning and Tracking groups, not its Core /
     * Financial / Terms). The source row is not touched.
     *
     * Does not open a transaction — the use case wraps the copy and its audit
     * row in one commit (`DB-11`), as `create()` is wrapped.
     *
     * @throws QuotationWriteRefused `version_exists` — `quotations_version_unique_alive` (`DB-03`) refused a second copy of this parent, read from the database's own refusal rather than a read-then-write two callers would both pass
     */
    public function copy(string $parentId, string $actorId): QuotationSummary;

    /**
     * `D-46`'s delete (Point 4.4), soft as `DB-01` requires: `deleted_at` on
     * the row and on both child tables, nothing physically gone. Guarded in
     * SQL the way `submit()` is — `WHERE version_token = $expectedToken` —
     * so a quotation edited from under the caller is not deleted. `false`
     * means the guard matched no row: stale, or already deleted. Draft-only
     * is the use case's rule; this writes, it does not decide.
     */
    public function delete(string $quotationId, int $expectedToken, string $actorId): bool;

    /**
     * `GET /quotations` (Point 5.3) — the approved Step 5 filters and sorts,
     * with `SEC-08`'s scope **in the query**: `own` is the deal's `owner_id`
     * (owner ruling 2026-09-11), reached as `deal_id IN` the owner's deals
     * through `DealFactsInterface::dealIdsOwnedBy()` — a set, not a join on
     * another module's table. A scope that permits nothing answers an empty
     * page before any read (`OpenAPI §5.1`). `total` is counted after scoping
     * (`OpenAPI §6.1`). Soft-deleted quotations are absent (`DB-01`).
     */
    public function list(QuotationListCriteria $criteria, QuotationRowScope $scope): QuotationPage;
}
