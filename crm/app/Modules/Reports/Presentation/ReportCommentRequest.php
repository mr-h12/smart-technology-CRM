<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** `D-127` — one comment; the body is the note's: required text, 5000 at most. */
final class ReportCommentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => (string) __('reports.attributes.comment_body')];
    }

    public function body(): string
    {
        /** @var array{body: string} $validated the rule above */
        $validated = $this->validated();

        return $validated['body'];
    }
}
