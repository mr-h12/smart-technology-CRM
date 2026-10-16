<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `D-131` replaced the one notes box with notes written through
 * `/reports/{id}/notes`; the old field is refused, as `D-122` did for customers.
 */
final class UpdateReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['notes' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['notes' => (string) __('reports.attributes.notes')];
    }
}
