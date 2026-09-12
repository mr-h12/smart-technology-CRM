<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/**
 * What `/quotations` answers with — Module 7 Points 3.4 and 3.5.
 *
 * `of()` is `OpenAPI §4.1`'s example to the field: `{id, code}` — the create's
 * answer. `detail()` is the read's: §10's header, both child tables, `§8.2`'s
 * audit fields and `§9.2`'s `etag` (`quotation:<id>:<version_token>`, the
 * document's own example shape, which Point 3.6's `If-Match` will compare).
 *
 * `$withCosts` is §3.5's "view cost & margin", decided by `ShowQuotation`.
 * Without it the cost fields are **absent**, not null: `margin_percent` null
 * already means "inherits the header's margin", so a null here would be a
 * lie about the line, and a zero would be a lie about the money.
 *
 * `warnings()` is §5.6's "warn, do not block", carried in `meta` the way `D-35`'s
 * `similar_customers` is: the warning is about the save, not a field of the
 * quotation. Each entry is the same `{field, code, message}` triple `OpenAPI §5.1`
 * gives error details, so the SPA reads a warning and a refusal with one reader,
 * and `field` names the request's line (`lines.N.quantity`, 0-based like the
 * validator's own paths) rather than a supplier item id the caller would have
 * to match by hand.
 */
final class QuotationPayload
{
    /** @return array{id: string, code: string} */
    public static function of(QuotationSummary $quotation): array
    {
        return [
            'id' => $quotation->id,
            'code' => $quotation->code,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(QuotationDetail $quotation, bool $withCosts): array
    {
        return [
            'id' => $quotation->id,
            'code' => $quotation->code,
            'deal_id' => $quotation->dealId,
            'customer_id' => $quotation->customerId,
            'quotation_date' => $quotation->quotationDate,
            'valid_until' => $quotation->validUntil,
            'status' => $quotation->status,
            'currency_id' => $quotation->currencyId,
            ...($withCosts ? ['default_margin' => $quotation->defaultMargin] : []),
            'discount_percent' => $quotation->discountPercent,
            'tax_percent' => $quotation->taxPercent,
            'rounding_unit' => $quotation->roundingUnit,
            'rounding_enabled' => $quotation->roundingEnabled,
            'subtotal' => $quotation->subtotal,
            'additional_total' => $quotation->additionalTotal,
            'discount_amount' => $quotation->discountAmount,
            'tax_base' => $quotation->taxBase,
            'tax_amount' => $quotation->taxAmount,
            'net_amount' => $quotation->netAmount,
            'total_before_round' => $quotation->totalBeforeRound,
            'final_total' => $quotation->finalTotal,
            'rounding_diff' => $quotation->roundingDiff,
            'payment_terms' => $quotation->paymentTerms,
            'warranty' => $quotation->warranty,
            'delivery_terms' => $quotation->deliveryTerms,
            'show_delivery_terms' => $quotation->showDeliveryTerms,
            'version' => $quotation->version,
            'parent_id' => $quotation->parentId,
            'rejection_reason' => $quotation->rejectionReason,
            'sent_at' => $quotation->sentAt,
            'is_self_approved' => $quotation->isSelfApproved,
            'etag' => 'quotation:'.$quotation->id.':'.$quotation->versionToken,
            'items' => array_map(
                static fn (QuotationLine $line): array => [
                    'id' => $line->id,
                    'line_no' => $line->lineNo,
                    'supplier_quotation_item_id' => $line->supplierQuotationItemId,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'line_total' => $line->lineTotal,
                    ...($withCosts ? [
                        'unit_cost' => $line->unitCost,
                        'unit_cost_currency' => $line->unitCostCurrency,
                        'unit_cost_fx_rate_at_time' => $line->unitCostFxRateAtTime,
                        'unit_cost_base' => $line->unitCostBase,
                        'margin_percent' => $line->marginPercent,
                        'line_cost' => $line->lineCost,
                    ] : []),
                ],
                $quotation->items,
            ),
            'additional_items' => array_map(
                static fn (QuotationAdditionalLine $line): array => [
                    'id' => $line->id,
                    'line_no' => $line->lineNo,
                    'description' => $line->description,
                    'amount' => $line->amount,
                ],
                $quotation->additionalItems,
            ),
            'created_by' => $quotation->createdBy,
            'created_at' => $quotation->createdAt->format(DATE_ATOM),
            'updated_by' => $quotation->updatedBy,
            'updated_at' => $quotation->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param  list<int>  $lineNumbers  1-based, as `QuotationCreated::$quantityWarnings` reports them
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function warnings(array $lineNumbers): array
    {
        return array_map(static fn (int $lineNo): array => [
            'field' => 'lines.'.($lineNo - 1).'.quantity',
            'code' => 'quantity_exceeds_recorded',
            'message' => (string) __('quotations.warnings.quantity_exceeds_recorded'),
        ], $lineNumbers);
    }
}
