<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `PATCH /api/v1/currencies/{code}`.
 *
 * **At least one of the two.** A `PATCH` naming neither the unit nor the switch
 * has nothing to do, and answering 200 would claim a write that never happened.
 *
 * **`gt:0` and not merely `numeric`.** `RoundingRule` refuses a non-positive
 * unit through `Decimal::positive()` and the column has a CHECK, but a rule
 * that only asked for a number would turn a documented refusal into a 500 —
 * `OpenAPI §5.1` wants 422 for a value the caller can fix.
 *
 * The unit stays a **string** all the way down (`DB-07`): `numeric` asks
 * whether it reads as a number, not whether PHP can make it a float.
 */
final class UpdateCurrencyRoundingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rounding_unit' => ['sometimes', 'required', 'string', 'numeric', 'gt:0'],
            'rounding_enabled' => ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->hasAny(['rounding_unit', 'rounding_enabled'])) {
            // Nothing to change. Expressed as a failing rule rather than a
            // thrown exception so the caller gets §5.1's 422 with a field name.
            $this->merge(['rounding_unit' => null]);
        }
    }
}
