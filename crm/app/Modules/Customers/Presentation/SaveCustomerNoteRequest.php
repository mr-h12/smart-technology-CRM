<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `D-122` — one customer note. `body` arrives trimmed and an empty one as null
 * (the framework's global middleware), so `required` refuses a blank note
 * before the table's CHECK would answer it with a 500.
 */
class SaveCustomerNoteRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            // A calendar date, a label only (`D-15` stays deferred).
            'note_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'body' => (string) __('customers.attributes.note_body'),
            'note_date' => (string) __('customers.attributes.note_date'),
        ];
    }

    /** @return array{body: string, note_date?: string|null} */
    public function changes(): array
    {
        /** @var array{body: string, note_date?: string|null} $validated the rules above */
        $validated = $this->validated();

        return $validated;
    }
}
