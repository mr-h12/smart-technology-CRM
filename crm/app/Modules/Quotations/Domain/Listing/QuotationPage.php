<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * One page of quotations, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination` — derived here, not in the serialiser, on `DealPage`'s
 * precedent.
 */
final readonly class QuotationPage
{
    /** @param list<QuotationSummary> $items */
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
