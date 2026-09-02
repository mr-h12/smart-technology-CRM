<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;

/**
 * How a supplier quotation appears on the wire — §7.2's header fields, in
 * `OpenAPI §8`'s shapes. `CatalogItemPayload`'s shape (Module 4 Point 3.1).
 *
 * `total_price` is a **string**, not a number: `DB-07` forbids float anywhere
 * near a price and `D-68` fixes the scale at six decimals, both of which JSON's
 * number type would quietly undo. `currency_id` rather than a three-letter
 * code, because that is the column (Point 1.1) and a caller that filters by it
 * needs the value it filters with.
 *
 * **The lines are not here.** `OpenAPI §4.1` asks for a single-resource
 * envelope and says nothing about nesting children; reading them back is
 * `GET /{id}`, which is Point 2.3. A field with no read path behind it would be
 * this point inventing one.
 *
 * `offer_date` and `valid_until` are `YYYY-MM-DD`, not timestamps: they are the
 * day a supplier priced an offer, and `DB-08`'s UTC rule is about instants.
 */
final class SupplierQuotationPayload
{
    /** @return array<string, mixed> */
    public static function of(SupplierQuotationSummary $quotation): array
    {
        return [
            'id' => $quotation->id,
            'code' => $quotation->code,
            'supplier_id' => $quotation->supplierId,
            'deal_id' => $quotation->dealId,
            'total_price' => $quotation->totalPrice,
            'currency_id' => $quotation->currencyId,
            'offer_date' => $quotation->offerDate,
            'valid_until' => $quotation->validUntil,
            'notes' => $quotation->notes,
        ];
    }
}
