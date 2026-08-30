<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Listing;

/**
 * One page of suppliers, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * The arithmetic lives here rather than in the serialiser for the reason
 * Module 3 gives: arithmetic in a serialiser is arithmetic no unit test reaches.
 */
final readonly class SupplierPage
{
    /** @param list<SupplierSummary> $items */
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
