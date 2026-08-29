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
    /**
     * Human names for the eight fields — `OpenAPI §5.1`'s `details[].message`
     * is read by a person.
     *
     * **Measured before it was written.** Without this, `PATCH /settings` with a
     * bad tax value answered *"The settings.defaults.tax percent field must be
     * a number."* — Laravel derives an attribute name from the rule path, and
     * the rule path here carries the `settings.` prefix and the escaped dot
     * that `rules()` needs. The internal key reached the screen.
     *
     * Translated, because §14.2 requires Arabic and English from the first
     * release and this string is shown to a user, not matched by a client.
     * The stable machine code beside it (`details[].code`) stays English.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SystemSetting::cases() as $setting) {
            // ⚠️ **Two different key shapes on one line, and both are
            // measured rather than assumed.**
            //
            // The array key is the **unescaped** attribute path — unlike
            // `rules()`, which needs `\.` because a rule key is parsed for
            // nesting. This array is looked up with the *resolved* attribute,
            // which has real dots in it. With the escaped key the message still
            // read "The settings.defaults.tax percent field must be a number."
            //
            // The **lang** key uses underscores, because `__()` reads dots as
            // nesting too: `admin.settings.attributes.defaults.tax_percent`
            // looks for `attributes → defaults → tax_percent`, which is not how
            // the file is shaped, and an unresolved key returns itself — the
            // message then read "The admin.settings.attributes.defaults.tax_percent
            // field...". This is the third time a dot has meant nesting in this
            // module; Point 3.1 paid for the first. The rule path needs `\.`
            // because a rule key is parsed for nesting; this array is looked up
            // with the *resolved* attribute, which has real dots in it.
            // Measured: with the escaped key the message still read "The
            // settings.defaults.tax percent field must be a number."
            $attributes['settings.'.$setting->value] = (string) __('admin.settings.attributes.'.str_replace('.', '_', $setting->value));
        }

        return $attributes;
    }

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
