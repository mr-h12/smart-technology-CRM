<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * `GET /purchase-orders/{id}` (Module 10 · 2.2): the order and the names the
 * owner asked for beside it — the customer, the deal and its owner, who
 * recorded it — and whether a file is attached (2.3 uploads it). A name the
 * caller may not see, or that no longer resolves, is null.
 */
final readonly class PurchaseOrderDetail
{
    public function __construct(
        public PurchaseOrderRecord $order,
        public ?string $customerName,
        public ?string $dealCode,
        public ?string $dealOwnerId,
        public ?string $dealOwnerName,
        public ?string $createdByName,
        public bool $hasAttachment,
    ) {}
}
