<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Listing;

/**
 * One page of deals, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * Derived here rather than in the serialiser, on `CustomerPage`'s precedent
 * (Module 3 Point 3.2): five of the six are arithmetic on the other two, and
 * arithmetic that lives in a serialiser is arithmetic no unit test reaches.
 */
final readonly class DealPage
{
    /** @param list<DealSummary> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        // An empty result is one (empty) page, not zero — see CustomerPage.
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
