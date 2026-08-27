<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Domain\Settings\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `PATCH /api/v1/settings`.
 *
 * **`settings` is required and must not be empty.** A `PATCH` with nothing to
 * change cannot be answered honestly — a 200 would claim a write that never
 * happened — so it is a 422 naming the field.
 *
 * **Unknown keys are refused, not ignored.** The closure below is what stops a
 * key/value table from becoming a dumping ground; silently dropping a key would
 * return 200 and store nothing, which is the same lie in a quieter voice.
 *
 * Authorisation is **not** here: the route carries
 * `permission:admin.system_settings`. §3.12 rule 1 puts enforcement at the API,
 * and this project keeps that check in one place rather than splitting it
 * between middleware and form requests.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $known = array_map(static fn (SystemSetting $s): string => $s->value, SystemSetting::cases());

        $rules = [
            'settings' => [
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

        // Per-field rules. `string` is on every one because the column is text
        // (`DB-07`); the extra rule is what §13's field actually is — a tax is a
        // number, a company name is not.
        //
        // ⚠️ **The dot in a key is escaped, and it has to be.** `company.name`
        // is one flat key, but a validation rule path reads dots as nesting, so
        // `settings.company.name` describes `settings[company][name]` — a level
        // that does not exist. Measured before it was fixed: every rule silently
        // matched nothing, `validated()` came back without `settings` at all,
        // and the controller died on an undefined key with a 500 rather than
        // refusing anything. Laravel's escape for this is a backslash.
        foreach (SystemSetting::cases() as $setting) {
            $path = 'settings.'.str_replace('.', '\\.', $setting->value);

            $rules[$path] = ['sometimes', 'required', 'string', $setting->rule()];
        }

        return $rules;
    }
}
