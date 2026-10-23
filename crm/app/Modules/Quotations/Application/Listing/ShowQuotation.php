<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Pricing\LegacyEntry;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;

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
 * an interface change. The list reads the set form, `dealIdsOwnedBy()`, on
 * the same seam (Point 5.1); a single-row read has no set to ask for.
 *
 * ── One 404 for absent and for out of reach ────────────────────────────────
 *
 * `OpenAPI §5.1`: "does not exist or is not visible to the caller. Do not
 * reveal which case applies." A scope that permits nothing (`asgn` —
 * granted by §3.5 and backed by nothing) is answered before the read, with
 * the same 404, so a caller probing ids learns nothing from timing or from
 * the code.
 */
final readonly class ShowQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private DealFactsInterface $deals,
        private AuthorizeAction $authorize,
        private CatalogItemLabelsInterface $catalogLabels,
        private SupplierLookupInterface $suppliers,
        private UserFactsInterface $users,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws QuotationNotFound
     */
    public function one(string $quotationId, array $heldScopes, string $actorId): QuotationDetail
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...));

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
     * `D-155`: each line's supplier, by its name — Suppliers' port. A supplier it cannot name is left out.
     *
     * @return array<string, string> supplier id => name
     */
    public function supplierNames(QuotationDetail $quotation): array
    {
        return $this->suppliers->namesFor(array_values(array_unique(array_map(
            static fn (QuotationLine $line): string => $line->supplierId,
            $quotation->items,
        ))));
    }

    /**
     * What the builder loads (cost viewers only): the quotation as entry values. A `legacy` one is converted
     * (`D-165`) — typed at its cost, the tax beside it (`D-167`), with its whole margin — so the next save prices it the new way.
     *
     * @param  array<string, string>  $lineNames  {@see self::lineNames()}
     * @param  array<string, string>  $supplierNames  {@see self::supplierNames()}
     * @return array{pricing_mode: string, lines: list<array<string, string|null>>}
     */
    public function entry(QuotationDetail $quotation, array $lineNames, array $supplierNames): array
    {
        $legacy = $quotation->pricingMode === PricingMode::Legacy;
        $legacyTax = $quotation->taxPercent === null ? null : Decimal::of($quotation->taxPercent, 'tax_percent');
        $lines = [];

        foreach ($quotation->items as $line) {
            $typed = $legacy
                ? LegacyEntry::line(
                    Decimal::of($line->unitCostBase, 'unit_cost_base'),
                    $line->marginPercent === null ? null : Decimal::of($line->marginPercent, 'margin_percent'),
                    Decimal::of($quotation->defaultMargin ?? '0', 'default_margin'),
                    $legacyTax,
                )
                : ['entered_unit_price' => $line->enteredUnitPrice, 'tax_percent' => $line->taxPercent, 'margin_percent' => $line->marginPercent];

            $lines[] = [
                'supplier_id' => $line->supplierId,
                'supplier_name' => $supplierNames[$line->supplierId] ?? null,
                'catalog_item_id' => $line->catalogItemId,
                'product_name' => $lineNames[$line->id] ?? null,
                'unit_code' => $line->unitCode,
                ...$typed,
                'quantity' => $line->quantity,
            ];
        }

        return [
            'pricing_mode' => ($legacy ? LegacyEntry::mode($legacyTax) : $quotation->pricingMode)->value,
            'lines' => $lines,
        ];
    }

    /**
     * F-16 · 1.1, QS.1 · `D-155`: what each line is — its own catalog item, by
     * §7.3's label. An item the catalog cannot name is left out, and the wire
     * carries null.
     *
     * @return array<string, string> quotation line id => label
     */
    public function lineNames(QuotationDetail $quotation): array
    {
        $catalogIds = [];

        foreach ($quotation->items as $line) {
            $catalogIds[$line->id] = $line->catalogItemId;
        }

        $labels = $this->catalogLabels->labelsOf(array_values(array_unique($catalogIds)));
        $names = [];

        foreach ($catalogIds as $lineId => $catalogId) {
            if (isset($labels[$catalogId])) {
                $names[$lineId] = $labels[$catalogId];
            }
        }

        return $names;
    }
}
