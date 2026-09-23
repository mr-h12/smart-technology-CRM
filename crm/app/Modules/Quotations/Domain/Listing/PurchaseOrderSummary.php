<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * A purchase order as its acceptance answers it (Module 10 · 1.6, §4.6):
 * both numbers (`D-53`) and the date (`D-12`); `id` for 2.3's upload.
 */
final readonly class PurchaseOrderSummary
{
    public function __construct(
        public string $id,
        public string $quotationId,
        public string $poNumber,
        public string $customerPoReference,
        public string $poDate,
    ) {}
}
