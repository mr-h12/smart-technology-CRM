<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `D-131` — one follow-up: a customer and a note. Text arrives trimmed and an
 * empty one as null (the framework's global middleware), so `required` refuses
 * a blank note before the table's CHECK would. `PATCH` sends either or both;
 * the customer's reach is the use case's (`ManageReportRows`).
 */
final class ReportFollowUpRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];

        return [
            'customer_id' => [...$required, 'string', 'uuid'],
            'note' => [...$required, 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id' => (string) __('reports.attributes.customer_id'),
            'note' => (string) __('reports.attributes.follow_up_note'),
        ];
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        /** @var array<string, string> $validated the rules above */
        $validated = $this->validated();

        return $validated;
    }
}
