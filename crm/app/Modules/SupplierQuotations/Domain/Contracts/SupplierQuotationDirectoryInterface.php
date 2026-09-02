<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Contracts;

use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationDetail;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;

/**
 * The `supplier_quotations` table as §7.2's screen needs to write it —
 * `DealDirectoryInterface`'s shape (Module 5 Point 2.2), with one deliberate
 * difference and one deliberate absence.
 *
 * ── No `RowScope` parameter anywhere, unlike Deals and Customers ───────────
 *
 * `SEC-08` is row-level security, and every reader in those modules takes a
 * scope because §3.2 gives their roles `Own`, `Team`, `Out` or `Asgn`. §3.6
 * gives this resource none: every role that sees it sees `All`, under the
 * heading "A shared screen — not restricted by ownership". A scope parameter
 * here would be a filter with exactly one possible value — the "parameter every
 * caller passes the same value for" that `CLAUDE.md`'s waste audit names. If
 * §3.6 ever gains a scope, this signature changes and the crossing is a
 * decision in a diff.
 *
 * ── Two methods, added one point at a time ────────────────────────────────
 *
 * Point 1.3 published `create()` alone, on the rule that an interface ahead
 * of its implementation is a promise the next point must keep in a shape it
 * did not choose. `find()` arrives now with Point 2.3, which implements it.
 * (1.3's docblock said "`find()` is Point 2.2"; the owner's four-way split of
 * Step 2, approved 2026-09-02, moved the read to 2.3.) `list()` is Point 4.1
 * and is still not here.
 */
interface SupplierQuotationDirectoryInterface
{
    /**
     * Allocates the `SQ-YYYY-NNNN` code internally (§4.7, via
     * `document_sequences`) — the caller supplies a draft, not a code, so it
     * cannot collide with another writer's allocation.
     *
     * `$actorId` is `DB-02`'s `created_by`/`updated_by`, and it is a parameter
     * rather than a draft field because §7.2's `entered_by` is a fact about who
     * was authenticated, never about what was submitted.
     *
     * Writes the header only. §7.2's line items are Point 1.2's table and
     * Step 2 Point 2.1's transaction.
     */
    public function create(SupplierQuotationDraft $draft, string $actorId): SupplierQuotationSummary;

    /**
     * §7.2's screen for one offer — the header **and** its lines, which is
     * what separates this from `create()`'s return.
     *
     * Returns `null` for an absent or `DB-01` soft-deleted row rather than
     * throwing: `OpenAPI §5.1`'s 404 is a contract decision, and it is made
     * once in the use case instead of once per caller.
     *
     * No scope parameter, for the reason this interface already gives.
     */
    public function find(string $quotationId): ?SupplierQuotationDetail;
}
