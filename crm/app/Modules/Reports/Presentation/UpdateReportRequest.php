<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** §11.3's free-text notes; empty clears them. */
final class UpdateReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'notes' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function attributes(): array
    {
        return ['notes' => (string) __('reports.attributes.notes')];
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && trim($notes) !== '' ? $notes : null;
    }
}
