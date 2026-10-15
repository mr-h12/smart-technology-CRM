<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/** §11.4: Returned carries a mandatory reason; blank is no reason. */
final class ReturnReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => (string) __('reports.attributes.reason')];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : '';
    }
}
