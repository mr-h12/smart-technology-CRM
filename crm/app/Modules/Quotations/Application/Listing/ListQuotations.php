<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/**
 * §6.6's list with `SEC-08`'s reach applied once — `ListDeals::handle()`'s
 * shape: a controller that resolved its own scope would be a second answer to
 * the same question. The list never asks `ShowQuotation::revealsCosts()`
 * because its row (Q6) carries nothing that needs the grant.
 */
final readonly class ListQuotations
{
    public function __construct(private QuotationDirectoryInterface $quotations, private DealFactsInterface $deals) {}

    /** @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them */
    public function handle(QuotationListCriteria $criteria, array $heldScopes, string $actorId): QuotationPage
    {
        return $this->quotations->list($criteria, QuotationRowScope::resolve($heldScopes, $actorId));
    }

    /**
     * Point 5.5 — `OpenAPI §6.2` "server-side grouping only": the same page,
     * bucketed by `group_by`. `employee` is the deal's owner through
     * `ownersOf()` (5.1) — a deal with no owner is the `null` group; `customer`
     * is the row's own `customer_id` (Q7). Rows keep the page's order inside a
     * group; the caller orders the groups by the label it renders.
     *
     * Pagination counts quotations, not groups (§6.1's bound on every list), so
     * a group may span two pages — stated, not hidden.
     *
     * @return list<array{key: ?string, items: non-empty-list<QuotationSummary>}>
     */
    public function grouped(QuotationPage $page, string $groupBy): array
    {
        $owners = $groupBy === 'employee'
            ? $this->deals->ownersOf(array_values(array_unique(array_map(static fn (QuotationSummary $row): string => $row->dealId, $page->items))))
            : [];

        $groups = [];
        foreach ($page->items as $row) {
            $key = $groupBy === 'employee' ? ($owners[$row->dealId] ?? null) : $row->customerId;
            $groups[$key ?? ''][] = $row;
        }

        $out = [];
        foreach ($groups as $key => $items) {
            $out[] = ['key' => $key === '' ? null : (string) $key, 'items' => $items];
        }

        return $out;
    }
}
