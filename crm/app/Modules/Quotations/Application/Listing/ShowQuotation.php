<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;

/**
 * `GET /quotations/{id}` — Module 7 Point 3.5. Reads one quotation inside
 * §3.5's `view` scope, and decides whether the caller may see its costs.
 *
 * ── The scope is applied to the deal's owner, after the read ───────────────
 *
 * "Own" is the deal's `owner_id` (owner ruling 2026-09-11, Point 3.4), and that
 * column belongs to Deals. `ListDeals::one()` filters in the query because the
 * column is its own; this class cannot, so it reads the row, asks
 * `DealFactsInterface` who owns its deal, and applies `QuotationRowScope` to
 * the answer — the seam and the check `CreateQuotation::guardDeal()` already
 * uses. Two queries instead of one join is the price of not reaching into
 * another module's table, and on a single-row read it is not a price worth
 * an interface change.
 *
 * ponytail: a per-row owner lookup. Step 5's list needs the set form — a
 * `dealIdsOwnedBy()` on the seam, or the owner id denormalised onto
 * `quotations` — and that is the point to add it, not this one.
 *
 * ── One 404 for absent and for out of reach ────────────────────────────────
 *
 * `OpenAPI §5.1`: "does not exist or is not visible to the caller. Do not
 * reveal which case applies." A scope that permits nothing (`team`, `asgn` —
 * granted by §3.5 and backed by nothing) is answered before the read, with
 * the same 404, so a Team Leader probing ids learns nothing from timing or
 * from the code.
 */
final readonly class ShowQuotation
{
    /** §10.3's first row: the statuses whose captured prices are still compared with the supplier's. */
    private const DRIFT_STATUSES = ['draft', 'pending'];

    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private DealFactsInterface $deals,
        private AuthorizeAction $authorize,
        private SupplierItemPricingInterface $supplierPrices,
        private CurrencyRepositoryInterface $currencies,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws QuotationNotFound
     */
    public function one(string $quotationId, array $heldScopes, string $actorId): QuotationDetail
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId);

        if ($scope->permitsNothing()) {
            throw QuotationNotFound::of($quotationId);
        }

        $quotation = $this->quotations->find($quotationId);

        if (! $quotation instanceof QuotationDetail) {
            throw QuotationNotFound::of($quotationId);
        }

        if (! $scope->unrestricted && ! $scope->reaches($this->deals->factsOf($quotation->dealId)?->ownerId)) {
            throw QuotationNotFound::of($quotationId);
        }

        return $quotation;
    }

    /**
     * §3.5's `view cost & margin` — a database grant (`SEC-07`), asked through
     * Identity's Application entry point the way `DealAttachmentPermission`
     * asks about `deal.view`. The row's scope is not re-checked: the caller
     * already reached the row through `one()`, and this row of §3.5 carries
     * checkmarks, not a reach of its own.
     */
    public function revealsCosts(string $actorId): bool
    {
        return $this->authorize->decide($actorId, 'quotation', 'view_cost_and_margin')->granted;
    }

    /**
     * `D-36` / §10.3 (Point 4.5): the lines whose supplier price moved since
     * the quotation captured it — the same seam 3.3 prices through, read the
     * same way (`priceFor()`, then the currency's code). The quotation keeps
     * its price; this only says which lines to review. `sent` and beyond
     * compare nothing: the document is a fixed snapshot. A line whose supplier
     * price is gone (`null`) or whose currency the offer never recorded counts
     * as moved — the captured price no longer matches anything current.
     *
     * @return list<int> 1-based line numbers, as `QuotationCreated::$quantityWarnings` reports them
     */
    public function movedLines(QuotationDetail $quotation): array
    {
        if (! in_array($quotation->status, self::DRIFT_STATUSES, true)) {
            return [];
        }

        $moved = [];

        foreach ($quotation->items as $line) {
            $price = $this->supplierPrices->priceFor($line->supplierQuotationItemId);
            $currency = $price?->currencyId === null ? null : $this->currencies->findById($price->currencyId);

            if ($price === null || $currency === null
                || $currency->code()->value !== $line->unitCostCurrency
                || bccomp(Decimal::of($price->unitPrice, 'unit_price'), Decimal::of($line->unitCost, 'unit_cost'), PricedLine::SCALE) !== 0) {
                $moved[] = $line->lineNo;
            }
        }

        return $moved;
    }
}
