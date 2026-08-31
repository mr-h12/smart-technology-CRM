<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The boundary for `PATCH /deals/{deal}/reject`.
 *
 * §4.3: "rejection_reason | Mandatory on rejection" — `regex:/\S/` beside
 * `required` because `required` alone accepts "   ", the same reasoning
 * `SaveCustomerRequest` gives `name`. The table's own CHECK (Point 1.1) would
 * catch a blank reason too, but as a 500; this refuses it first as the 422 it
 * actually is.
 */
final class RejectDealRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'regex:/\S/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reason' => (string) __('deals.attributes.rejection_reason'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.regex' => (string) __('deals.validation.reason_not_blank'),
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        if (! is_string($reason)) {
            throw new RuntimeException('The reject request validated a non-string reason.');
        }

        return $reason;
    }
}
