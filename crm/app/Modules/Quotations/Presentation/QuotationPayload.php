<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/**
 * What `POST /quotations` answers with — Module 7 Point 3.4.
 *
 * `of()` is `OpenAPI §4.1`'s example to the field: `{id, code}`. `QuotationSummary`
 * carries exactly that today; Point 3.5's `GET /{id}` is where the detail grows.
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
