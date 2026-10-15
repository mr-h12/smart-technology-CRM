<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** This slice builds the daily report only; `D-125`'s other five land in later slices. */
final class CreateReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:daily'],
        ];
    }

    public function attributes(): array
    {
        return ['type' => (string) __('reports.attributes.type')];
    }
}
