<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/** One page of `GET /purchase-orders`, counted after scoping (`OpenAPI §6.1`). */
final readonly class PurchaseOrderPage
{
    /**
     * @param  list<PurchaseOrderRecord>  $items
     * @param  array<string, string>  $customerNames
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public array $customerNames = [],
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
