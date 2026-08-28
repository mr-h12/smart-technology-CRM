<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * `POST /api/v1/fx-rates` — the shape of one submitted rate.
 *
 * **What this validates is shape, not meaning.** Whether `USD` is a currency
 * this system currently offers is a question only the database answers, and
 * `RecordFxRate` asks it; a `Rule::in(CurrencyCode::cases())` here would pass
 * `EUR` on the day it was archived and put the refusal in two places that could
 * disagree.
 *
 * **`rate` is checked by pattern and not by `numeric`, and that is `DB-07`.**
 * `numeric` accepts `4.85e1`, leading whitespace and a trailing dot;
 * `Decimal::of` accepts none of them, so `numeric` alone would let a rate past
 * the boundary and turn a caller's typo into a 500 two layers down. The pattern
 * is `Decimal`'s, minus the sign — a negative rate is not a rate — and `gt:0`
 * is what refuses `0` and `0.00`, which the pattern happily matches.
 *
 * **`effective_from` is `date_format` and not `date`.** Laravel's `date` rule
 * is `strtotime`, which reads "last tuesday" as a date; `DB-08` stores UTC and
 * `OpenAPI` speaks ISO 8601, so the two ISO spellings are named exactly.
 */
final class RecordFxRateRequest extends FormRequest
{
    /** The route's `permission:admin.fx_rates` middleware is the authorisation (§3.12 rule 1). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The three accessors below exist because `validated()` returns `mixed`
     * and this project does not cast its way out of `mixed` — a `(string)` on a
     * submitted array is the string `"Array"`, and one of these three is
     * `DB-07`'s rate. `rules()` already guarantees each of them; the check is
     * what makes that guarantee true for static analysis, and true again on the
     * day somebody edits `rules()` without reading this class.
     *
     * The refusal is a `LogicException` and not a validation error: reaching it
     * means the rules and the accessors disagree, which is a defect rather than
     * something a caller submitted.
     */
    public function currency(string $field): string
    {
        $value = $this->validated($field);

        return is_string($value)
            ? strtoupper($value)
            : throw new LogicException("rules() marks {$field} required|string.");
    }

    public function rate(): string
    {
        $value = $this->validated('rate');

        return is_string($value) ? $value : throw new LogicException('rules() marks rate required|string.');
    }

    /**
     * Absent means "from now". A rate with no start date would have no moment
     * for `D-09` to fix a quotation against, so there is no third state — the
     * field is optional, the column is not nullable.
     */
    public function effectiveFrom(): DateTimeImmutable
    {
        $value = $this->validated('effective_from');

        return is_string($value) ? new DateTimeImmutable($value) : new DateTimeImmutable;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from_currency' => ['required', 'string', 'size:3'],
            'to_currency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/', 'numeric', 'gt:0'],
            'effective_from' => ['sometimes', 'required', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ];
    }
}
