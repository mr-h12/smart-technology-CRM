<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

use App\Modules\Quotations\Domain\Listing\QuotationDetail;

/**
 * The read-only half of {@see QuotationDirectoryInterface} — what another
 * module is granted when it needs a quotation and must not create, edit or
 * delete one (F-14 · 1.1; Module 9's PDF is the first). Collected with the
 * four value classes it returns into `deptrac.modules.yaml`'s
 * `QuotationsContract`, on `SuppliersContract`'s terms (F-10 · 1.4).
 *
 * One implementation, `EloquentQuotationDirectory`, which already had this
 * `find()`: the directory extends this interface rather than a second class
 * duplicating the read.
 *
 * **What a caller must still do:** `QuotationDetail` holds cost, margin and
 * supplier-line fields (`QuotationLine::COST_FIELDS`), so a customer-facing
 * reader maps it into its own model; and the read is unscoped (below), so the
 * caller applies §3.5's row scope itself.
 */
interface QuotationReaderInterface
{
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
}
