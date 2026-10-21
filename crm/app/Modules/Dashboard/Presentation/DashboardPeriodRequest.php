<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A dashboard period (`D-145`): `from` and `to` as calendar days, both included.
 * The route's `permission:dashboard.view` is the authorisation.
 */
final class DashboardPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function from(): string
    {
        return $this->string('from')->toString();
    }

    public function to(): string
    {
        return $this->string('to')->toString();
    }
}
