<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationDetail;
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
 * **`of()` carries the header alone and `detail()` adds the lines.** Point
 * 2.2's 201 answers with `of()`, because `OpenAPI §4.1` asks for a
 * single-resource envelope and says nothing about nesting children. Point
 * 2.3's `GET /{id}` is the read path the lines were waiting for, and it
 * calls `detail()` — which delegates the nine header fields to `of()` rather
 * than listing them a second time.
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

    /**
     * The detail body — §7.2's header, then its `Line items` row.
     *
     * `items` is always present, `[]` included: a caller rendering the lines
     * should not have to tell "no lines" from "this endpoint forgot them".
     *
     * The two numbers stay **strings** at `D-68`'s scales, for `total_price`'s
     * reason — JSON's number type would quietly undo `DB-07`.
     *
     * @return array<string, mixed>
     */
    public static function detail(SupplierQuotationDetail $quotation): array
    {
        return [
            ...self::of($quotation->header),
            'items' => array_map(
                static fn ($line): array => [
                    'catalog_item_id' => $line->catalogItemId,
                    'unit_price' => $line->unitPrice,
                    'quantity' => $line->quantity,
                ],
                $quotation->lines,
            ),
        ];
    }
}
