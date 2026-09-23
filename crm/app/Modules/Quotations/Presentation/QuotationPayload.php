<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Quotations\Application\Listing\ApprovalWaiting;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderDetail;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderPage;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderRecord;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderSummary;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;

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

    /**
     * One list row (Q6) — §6.6's columns and **nothing from
     * `QuotationLine::COST_FIELDS`** or `default_margin`: the list is read by
     * roles without `quotation.view_cost_and_margin`, so the row cannot carry
     * what the detail gates.
     *
     * @param  array<string, string>  $customerNames  the page's one `namesOf()` read (`D-83`)
     * @return array<string, mixed>
     */
    public static function summary(QuotationSummary $quotation, ApprovalWaiting $waiting, array $customerNames): array
    {
        return [
            'id' => $quotation->id,
            'code' => $quotation->code,
            'status' => $quotation->status,
            'customer_id' => $quotation->customerId,
            // F-07 · 1.3 — the name beside the id, as `currency` sits beside
            // `currency_id` (6.3, ruling A). An id the facts do not name stays
            // the id, the rule the group label already follows.
            'customer_name' => $customerNames[$quotation->customerId] ?? $quotation->customerId,
            'deal_id' => $quotation->dealId,
            'currency_id' => $quotation->currencyId,
            'currency' => $quotation->currency,
            'final_total' => $quotation->finalTotal,
            'quotation_date' => $quotation->quotationDate,
            'valid_until' => $quotation->validUntil,
            'submitted_at' => $quotation->submittedAt,
            // Module 8 Point 2.1 — `D-11`'s column and badge, server-computed.
            ...$waiting->of($quotation->status, $quotation->submittedAt),
            'is_self_approved' => $quotation->isSelfApproved,
            'version' => $quotation->version,
            'parent_id' => $quotation->parentId,
            'created_at' => $quotation->createdAt->format(DATE_ATOM),
            'updated_at' => $quotation->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * `OpenAPI §6.2`'s grouped `data` — `[{key, label, count, items}]`, groups
     * ordered by `label`. The label is what `ListQuotations::grouped()` named
     * the group — an employee's name (Step 6 Q2), a customer's name (`D-83`,
     * reversing Q7); the `null` group reads `quotations.groups.unassigned`.
     *
     * @param  list<array{key: ?string, label: ?string, items: non-empty-list<QuotationSummary>}>  $groups
     * @param  array<string, string>  $customerNames
     * @return list<array<string, mixed>>
     */
    public static function groups(array $groups, ApprovalWaiting $waiting, array $customerNames): array
    {
        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'key' => $group['key'],
                'label' => $group['label'] ?? (string) __('quotations.groups.unassigned'),
                'count' => count($group['items']),
                'items' => array_map(static fn (QuotationSummary $row): array => self::summary($row, $waiting, $customerNames), $group['items']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function many(QuotationPage $page, ApprovalWaiting $waiting): array
    {
        return array_map(static fn (QuotationSummary $row): array => self::summary($row, $waiting, $page->customerNames), $page->items);
    }

    /**
     * `OpenAPI §4.2`'s six — `DealPayload::pagination()`'s shape.
     *
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}
     */
    public static function pagination(QuotationPage|PurchaseOrderPage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }

    /**
     * @param  array<string, string>  $lineNames  `ShowQuotation::lineNames()` — a line it cannot name carries null (F-16 · 1.1)
     * @return array<string, mixed>
     */
    public static function detail(QuotationDetail $quotation, bool $withCosts, ApprovalWaiting $waiting, array $lineNames): array
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
            'currency' => $quotation->currency,
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
            'submitted_at' => $quotation->submittedAt,
            ...$waiting->of($quotation->status, $quotation->submittedAt),
            // Module 8 Point 1.2's marks, on the wire since 2.1.
            'returned_at' => $quotation->returnedAt,
            'return_note' => $quotation->returnNote,
            'is_self_approved' => $quotation->isSelfApproved,
            'etag' => QuotationEtag::of($quotation),
            // Module 10 · 2.2: always present, null before an acceptance.
            'purchase_order' => $quotation->purchaseOrder === null ? null : self::purchaseOrderReference($quotation->purchaseOrder),
            'items' => array_map(
                static fn (QuotationLine $line): array => [
                    'id' => $line->id,
                    'line_no' => $line->lineNo,
                    'product_name' => $lineNames[$line->id] ?? null,
                    ...($withCosts ? $line->asRow() : array_diff_key($line->asRow(), array_flip(QuotationLine::COST_FIELDS))),
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
     * The order as its quotation names it (1.6's answer, on the detail since 2.2).
     *
     * @return array<string, string>
     */
    public static function purchaseOrderReference(PurchaseOrderSummary $order): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->poNumber,
            'customer_po_reference' => $order->customerPoReference,
            'po_date' => $order->poDate,
        ];
    }

    /**
     * Module 10 · 2.2's list row — the owner's fields, nothing from the cost side.
     *
     * @return array<string, string|null>
     */
    public static function purchaseOrder(PurchaseOrderRecord $order, ?string $customerName): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->poNumber,
            'customer_po_reference' => $order->customerPoReference,
            'po_date' => $order->poDate,
            'quotation_id' => $order->quotationId,
            'quotation_code' => $order->quotationCode,
            'customer_id' => $order->customerId,
            'customer_name' => $customerName,
            'final_total' => $order->finalTotal,
            'currency' => $order->currency,
        ];
    }

    /**
     * Module 10 · 2.2's detail: the row, who and when, the deal, the status and
     * the quotation's breakdown. An exempt quotation has **no** tax keys (`D-63`).
     *
     * @return array<string, string|bool|null>
     */
    public static function purchaseOrderDetail(PurchaseOrderDetail $detail): array
    {
        $order = $detail->order;

        return [
            ...self::purchaseOrder($order, $detail->customerName),
            'created_at' => $order->createdAt,
            'created_by' => $order->createdBy,
            'created_by_name' => $detail->createdByName,
            'has_attachment' => $detail->hasAttachment,
            'deal_id' => $order->dealId,
            'deal_code' => $detail->dealCode,
            'deal_owner_id' => $detail->dealOwnerId,
            'deal_owner_name' => $detail->dealOwnerName,
            'quotation_status' => $order->quotationStatus,
            'subtotal' => $order->subtotal,
            'additional_total' => $order->additionalTotal,
            'discount_amount' => $order->discountAmount,
            ...($order->taxPercent === null ? [] : ['tax_percent' => $order->taxPercent, 'tax_amount' => $order->taxAmount]),
        ];
    }

    /**
     * One `meta.warnings` entry per line: 3.4's `quantity_exceeds_recorded` on
     * `quantity`, 4.5's `supplier_price_changed` on `unit_cost`. The message
     * is `quotations.warnings.<code>`.
     *
     * @param  list<int>  $lineNumbers  1-based, as `QuotationCreated::$quantityWarnings` and `ShowQuotation::movedLines()` report them
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function warnings(array $lineNumbers, string $code, string $attribute): array
    {
        return array_map(static fn (int $lineNo): array => [
            'field' => 'lines.'.($lineNo - 1).'.'.$attribute,
            'code' => $code,
            'message' => (string) __('quotations.warnings.'.$code),
        ], $lineNumbers);
    }
}
