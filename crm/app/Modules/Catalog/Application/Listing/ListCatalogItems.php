<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Listing;

use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Listing\CatalogItemListCriteria;
use App\Modules\Catalog\Domain\Listing\CatalogItemNotFound;
use App\Modules\Catalog\Domain\Listing\CatalogItemPage;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;

/**
 * §8's Catalog screen — the list and the detail.
 *
 * Thinner than `ListCustomers` by exactly the row scope §3.7 does not have.
 * The use case still exists rather than letting the controller hold the
 * directory: `OpenAPI §5.1`'s 404 rule is a decision, and a decision in a
 * controller is a decision the next controller makes differently.
 */
final readonly class ListCatalogItems
{
    public function __construct(private CatalogItemDirectoryInterface $items) {}

    public function handle(CatalogItemListCriteria $criteria): CatalogItemPage
    {
        return $this->items->list($criteria);
    }

    /** @throws CatalogItemNotFound when the row is absent or soft-deleted */
    public function one(string $catalogItemId): CatalogItemSummary
    {
        $item = $this->items->find($catalogItemId);

        if (! $item instanceof CatalogItemSummary) {
            throw CatalogItemNotFound::of($catalogItemId);
        }

        return $item;
    }
}
