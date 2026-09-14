<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The boundary for `PATCH /quotations/{quotation}/return` (Module 8 Point 1.2).
 *
 * §6.4 "return **with note**", §9 Flow 7 "mandatory reason" — `regex:/\S/`
 * beside `required` because `required` alone accepts "   ", the reasoning
 * `RejectDealRequest` gives its reason. `max` is the `text` column's practical
 * ceiling, not a business rule.
 */
final class ReturnQuotationRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'regex:/\S/', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.regex' => (string) __('quotations.validation.note_not_blank'),
        ];
    }

    public function note(): string
    {
        $note = $this->validated('note');

        if (! is_string($note)) {
            throw new RuntimeException('The return request validated a non-string note.');
        }

        return $note;
    }
}
