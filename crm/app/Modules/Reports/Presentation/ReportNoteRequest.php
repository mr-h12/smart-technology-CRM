<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** `D-131` — one note on a daily report; a blank body arrives as null and is refused. */
final class ReportNoteRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => (string) __('reports.attributes.note_body')];
    }

    /** @return array{body: string} */
    public function fields(): array
    {
        /** @var array{body: string} $validated the rule above */
        $validated = $this->validated();

        return $validated;
    }
}
