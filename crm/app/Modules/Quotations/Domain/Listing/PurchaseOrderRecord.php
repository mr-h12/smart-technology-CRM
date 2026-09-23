<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * A purchase order with the accepted quotation it was written from (Module 10
 * · 2.2): §4.6's fields, and the quotation's own totals as stored — the order
 * computes nothing. Never cost, margin or suppliers (the owner's 2.2 answer).
 * `taxPercent` / `taxAmount` are null on an exempt quotation (`D-63`).
 */
final readonly class PurchaseOrderRecord
{
    public function __construct(
        public string $id,
        public string $poNumber,
        public string $customerPoReference,
        public string $poDate,
        public string $createdAt,
        public ?string $createdBy,
        public string $quotationId,
        public string $quotationCode,
        public string $quotationStatus,
        public string $customerId,
        public string $dealId,
        public string $currency,
        public string $subtotal,
        public string $additionalTotal,
        public string $discountAmount,
        public ?string $taxPercent,
        public ?string $taxAmount,
        public string $finalTotal,
    ) {}
}
