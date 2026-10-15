<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** `D-127`: a report goes to somebody — at least one recipient, each once. */
final class SubmitReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'recipient_ids' => (string) __('reports.attributes.recipient_ids'),
            'recipient_ids.*' => (string) __('reports.attributes.recipient_ids'),
        ];
    }

    /** @return list<string> */
    public function recipientIds(): array
    {
        $ids = $this->validated('recipient_ids');

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
