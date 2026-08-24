<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `SEC-12` — backend validation at the boundary, for `POST /api/v1/users`.
 *
 * Shape only. Every rule that matters is asked again by {@see \App\Modules\Identity\Application\Administration\CreateUser}:
 * `D-28` through `PasswordPolicy`, §3.12 rule 7 through `RoleAssignmentPolicy`,
 * and the address through the directory inside the transaction. This layer may
 * reject more politely; it may never accept more.
 *
 * ── `role_id`, not `role` ──────────────────────────────────────────────────
 *
 * `OpenAPI §8.2`: "Represent direct relationships with explicit ID fields".
 *
 * ── `is_hidden` and `is_active` are not accepted ───────────────────────────
 *
 * They are absent from the rules on purpose, and `$request->validated()` is
 * what the controller passes on, so a payload carrying either is ignored rather
 * than honoured. §3.12 rule 6 is derived from the role, and activation is its
 * own audited action.
 */
final class CreateUserRequest extends FormRequest
{
    /** The route carries `permission:admin.create_user`; that is the authorisation. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // The partial unique index is `WHERE deleted_at IS NULL`
                // (Point 1.2), so the rule has to match it — the default
                // `unique` would count an archived account and refuse an
                // address D-34 says must be reusable.
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            // No `confirmed`: an administrator typing somebody else's initial
            // password has no second field on this form, and requiring one
            // would be a rule the documentation does not state.
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MINIMUM_LENGTH],
            'role_id' => [
                'required', 'uuid',
                Rule::exists('roles', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
