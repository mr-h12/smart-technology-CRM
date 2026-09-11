<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;

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
}
