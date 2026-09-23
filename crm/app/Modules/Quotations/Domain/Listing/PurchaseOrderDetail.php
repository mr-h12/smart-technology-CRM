<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use App\Modules\Storage\Domain\StoredFile;

/**
 * `GET /purchase-orders/{id}` (Module 10 · 2.2): the order and the names the
 * owner asked for beside it — the customer, the deal and its owner, who
 * recorded it — and its files (2.3, A1: `documents` replaced `has_attachment`).
 * A name the caller may not see, or that no longer resolves, is null.
 */
final readonly class PurchaseOrderDetail
{
    /** @param  list<StoredFile>  $documents  oldest first */
    public function __construct(
        public PurchaseOrderRecord $order,
        public ?string $customerName,
        public ?string $dealCode,
        public ?string $dealOwnerId,
        public ?string $dealOwnerName,
        public ?string $createdByName,
        public array $documents,
    ) {}
}
