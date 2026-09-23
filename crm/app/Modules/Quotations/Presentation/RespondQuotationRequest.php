<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The boundary for `PATCH /quotations/{quotation}/respond` (Module 10 Point 1.4,
 * `OpenAPI §7.2`). Owner, 2026-09-23: a field that belongs to another response
 * is refused, not dropped (A); `accepted` waits for 1.6 (B), `rejected` came
 * with 1.5. A missing reason is `RespondToQuotation`'s to refuse, because
 * the contract names its code (`rejection_reason_required`) and a Form Request
 * only says `invalid`.
 */
final class RespondQuotationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', Rule::in(['partial', 'counter', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:2000', 'prohibited_unless:response,counter,rejected'],
            'customer_po_reference' => ['prohibited'],
            'po_date' => ['prohibited'],
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
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
