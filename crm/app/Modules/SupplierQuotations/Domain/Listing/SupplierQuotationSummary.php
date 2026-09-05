<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

/**
 * A supplier quotation as a reader sees it — §7.2's header fields, and nothing
 * beyond them. `DealSummary`'s shape (Module 5 Point 2.2).
 *
 * `totalPrice` is a **string**, not a float: `DB-07` forbids float anywhere
 * near a price, and `D-68` fixes the scale at six decimals. The Eloquent cast
 * hands over a decimal string and it stays one all the way out.
 *
 * Two things are deliberately absent for the same reason: the lines Point 1.2
 * stores, and `created_at`/`updated_at`. Nothing reads either — `GET /{id}` is
 * Point 2.2 — and a field with no reader is the "unused component" the waste
 * audit exists to catch. Point 2.2 adds what its serialiser actually needs.
 */
final readonly class SupplierQuotationSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $supplierId,
        public ?string $dealId,
        public ?string $totalPrice,
        public ?string $currencyId,
        public ?string $offerDate,
        public ?string $validUntil,
        public ?string $notes,
    ) {}
}
