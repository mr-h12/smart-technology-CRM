<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/roles/{role}` — the labels and the description, and nothing
 * else.
 *
 * ── `slug` is not in `rules()`, and that is the enforcement ────────────────
 *
 * A `PATCH` body carrying a slug is ignored rather than refused, because
 * `validated()` returns only declared keys and {@see submitted()} reads from
 * it. The reason the slug is immovable at all is in
 * {@see \App\Modules\Identity\Application\RoleAdministration\UpdateRole}: it is
 * what `Role::tryFrom()` matches on, so changing it silently re-answers §3.12
 * rule 7 for every Manager.
 *
 * ⚠️ **`sometimes` on every field, and that is what makes this a PATCH.** A key
 * that is absent leaves the column alone; a key present with `null` clears it.
 * `nullable` alone cannot express the difference, which is why
 * {@see submitted()} rebuilds the array from `has()` rather than from
 * `validated()` defaults.
 */
final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:128'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'min:2', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'name_ar', 'description'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                // Trimmed to empty means "clear this field", which the two
                // nullable columns accept and `name` does not — `min:2` refuses
                // it, so a blank rename is a 422 rather than a nameless role.
                $trimmed = trim($value);

                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            }
        }
    }

    /**
     * The keys the caller actually sent, with their values.
     *
     * @return array{name?: string, name_ar?: string|null, description?: string|null}
     */
    public function submitted(): array
    {
        $validated = $this->validated();
        $submitted = [];

        if (array_key_exists('name', $validated) && is_string($validated['name'])) {
            $submitted['name'] = $validated['name'];
        }

        foreach (['name_ar', 'description'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $value = $validated[$field];

            $submitted[$field] = is_string($value) ? $value : null;
        }

        /** @var array{name?: string, name_ar?: string|null, description?: string|null} $submitted */
        return $submitted;
    }
}
