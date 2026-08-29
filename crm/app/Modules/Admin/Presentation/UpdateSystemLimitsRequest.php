<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Domain\Settings\SystemLimit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `PATCH /api/v1/system-limits`.
 *
 * The shape and every rule here are {@see UpdateSettingsRequest}'s, including
 * the two that were paid for once already:
 *
 * - **`limits` is required and non-empty.** A `PATCH` with nothing to change
 *   cannot be answered honestly; a 200 would claim a write that never happened.
 * - **Unknown keys are refused, not ignored.** Silently dropping a key returns
 *   200 and stores nothing, which is the same lie in a quieter voice.
 * - **⚠️ The dot in a key is escaped.** `identity.lockout_minutes` is one flat
 *   key, but a rule path reads dots as nesting, so `limits.identity.lockout_minutes`
 *   describes a level that does not exist. Point 3.1 measured what that costs:
 *   every per-field rule matched nothing and the controller died with a 500
 *   instead of refusing anything.
 *
 * Authorisation is on the route (`permission:admin.system_limits`), §3.12
 * rule 1, and §3.11's own row — not `system settings`, one line above it.
 */
final class UpdateSystemLimitsRequest extends FormRequest
{
    /**
     * §13 screen 6's field names, so a refusal reads as a sentence.
     *
     * **This is {@see UpdateSettingsRequest::attributes()}'s twin, and it was
     * missing.** Point 5.1 measured the defect on `PATCH /settings`; the same
     * one was live here and was found the same way, by building the screen
     * that shows the message. It read *"The limits.limits.stale deal days
     * field format is invalid."* — the internal key path in front of a person,
     * with the word `limits` in it twice because this key begins with the
     * prefix the rule path adds.
     *
     * ⚠️ **Two key shapes on one line, both measured rather than assumed.**
     * The array key is the **unescaped** path, unlike `rules()` below which
     * needs `\.`: a rule key is parsed for nesting, while this array is looked
     * up with the *resolved* attribute, which has real dots in it. The **lang**
     * key uses underscores, because `__()` reads dots as nesting too and an
     * unresolved key returns itself — the message would then read
     * *"The admin.limits.attributes.identity.lockout_minutes field…"*.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SystemLimit::cases() as $limit) {
            $attributes['limits.'.$limit->value] = (string) __(
                'admin.limits.attributes.'.str_replace('.', '_', $limit->value),
            );
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $known = array_map(static fn (SystemLimit $l): string => $l->value, SystemLimit::cases());

        $rules = [
            'limits' => [
                'required',
                'array',
                'min:1',
                static function (string $attribute, mixed $value, callable $fail) use ($known): void {
                    if (! is_array($value)) {
                        return;
                    }

                    foreach (array_keys($value) as $key) {
                        if (! in_array($key, $known, true)) {
                            $fail(__('validation.in', ['attribute' => (string) $key]));
                        }
                    }
                },
            ],
        ];

        foreach (SystemLimit::cases() as $limit) {
            $path = 'limits.'.str_replace('.', '\\.', $limit->value);

            $rules[$path] = ['sometimes', 'required', 'string', $limit->rule()];
        }

        return $rules;
    }
}
