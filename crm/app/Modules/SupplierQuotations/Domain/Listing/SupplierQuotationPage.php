<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

/**
 * One page of supplier quotations, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * `SupplierPage`'s shape and its reasoning: the arithmetic lives here rather
 * than in the serialiser, because arithmetic in a serialiser is arithmetic no
 * unit test reaches. `SupplierQuotationPageTest` is the test the other four
 * copies never got.
 */
final readonly class SupplierQuotationPage
{
    /** @param list<SupplierQuotationSummary> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        // An empty result is one (empty) page, not zero: `page=1` of an empty
        // list is a valid request, and reporting 0 makes `has_next_page` and
        // the SPA's paginator disagree about whether page 1 exists.
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
