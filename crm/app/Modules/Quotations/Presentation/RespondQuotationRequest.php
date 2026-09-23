<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The boundary for `PATCH /quotations/{quotation}/respond` (Module 10 Point 1.4,
 * `OpenAPI §7.2`). Owner, 2026-09-23: a field that belongs to another response
 * is refused, not dropped (A); `rejected` came with 1.5 and `accepted`, with its
 * two purchase-order fields, with 1.6. A missing reason is `RespondToQuotation`'s to refuse, because
 * the contract names its code (`rejection_reason_required`) and a Form Request
 * only says `invalid`.
 */
final class RespondQuotationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', Rule::in(['accepted', 'partial', 'counter', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:2000', 'prohibited_unless:response,counter,rejected'],
            // Owner B (1.6): a reference with a character (`TrimStrings` makes a
            // blank one null, so `required_if` refuses it) and a real date,
            // future allowed; 255 is the column, not a business rule.
            'customer_po_reference' => ['required_if:response,accepted', 'prohibited_unless:response,accepted', 'nullable', 'string', 'max:255'],
            'po_date' => ['required_if:response,accepted', 'prohibited_unless:response,accepted', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function customerResponse(): string
    {
        $response = $this->validated('response');

        if (! is_string($response)) {
            throw new RuntimeException('The respond request validated a non-string response.');
        }

        return $response;
    }

    public function reason(): ?string
    {
        return $this->optional('reason');
    }

    public function customerPoReference(): ?string
    {
        return $this->optional('customer_po_reference');
    }

    public function poDate(): ?string
    {
        return $this->optional('po_date');
    }

    private function optional(string $field): ?string
    {
        $value = $this->validated($field);

        return is_string($value) ? $value : null;
    }
}
