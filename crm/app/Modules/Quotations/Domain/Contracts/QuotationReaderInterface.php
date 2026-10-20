<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

use App\Modules\Quotations\Domain\Listing\PurchaseOrderRecord;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;

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
    /** `D-140`, `D-141`: the statuses a sent version counts as won — RPT-MN's won count and its money read this one list. */
    public const WON = ['accepted', 'partial'];

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
     * Quotation id => its list row (§6.6's columns, selling total only) for a
     * daily report's records (`D-130`). Unscoped as `find()` is; an absent or
     * soft-deleted id has no entry; the empty list answers `[]` without a query.
     *
     * @param  list<string>  $quotationIds
     * @return array<string, QuotationSummary>
     */
    public function summariesOf(array $quotationIds): array;

    /**
     * Purchase order id => its list row (`D-130`), on `purchaseOrders()`'s
     * terms: an order whose quotation is soft-deleted goes with it. Unscoped;
     * an absent or soft-deleted id has no entry.
     *
     * @param  list<string>  $purchaseOrderIds
     * @return array<string, PurchaseOrderRecord>
     */
    public function purchaseOrdersOf(array $purchaseOrderIds): array;

    /**
     * Status => how many live quotation versions were sent (`sent_at`) in
     * `[$from, $to)`, by their status now — RPT-MN's company-wide count
     * (`D-140`). Unscoped; statuses with none have no entry.
     *
     * @return array<string, int>
     */
    public function statusCountsSentBetween(\DateTimeInterface $from, \DateTimeInterface $to): array;

    /**
     * RPT-MN's money (`D-141`), company-wide and unscoped: per currency, each
     * quotation chain's latest live version sent (`sent_at`) in `[$from, $to)`,
     * counted only when it is now `accepted` or `partial`. Won value =
     * `net_amount`; cost = Σ `line_cost` of its live lines; profit = `net_amount`
     * − `additional_total` − cost. Exact decimal strings at the stored scale,
     * sorted by currency code; a currency with nothing won has no row.
     *
     * @return list<array{currency: string, won_value: string, cost: string, profit: string}>
     */
    public function wonMoneySentBetween(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
