<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `SEC-12` — backend validation on every field, at the boundary.
 *
 * ── What is here and what is not ───────────────────────────────────────────
 *
 * **Shape** is here: required, string, confirmed, and `D-28`'s minimum length
 * so the caller gets a field-level message on the obvious mistake. **The rule**
 * is not: `ChangePassword` asks {@see PasswordPolicy} again, and it is that
 * check — not this one — that decides. A Form Request is one entry point, and
 * a policy that only exists in one is a policy the next entry point will not
 * have. The duplication is deliberate and one-directional: this may reject
 * more politely, never accept more.
 *
 * `confirmed` expects a `new_password_confirmation` field; that is Laravel's
 * convention and the contract the SPA is written against.
 */
final class ChangePasswordRequest extends FormRequest
{
    /** The route carries `auth`; authorisation to change *your own* password is having one. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // No max and no policy rule: the hasher takes whatever arrives, and
            // rejecting a long passphrase would be a rule D-28 does not state.
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:'.PasswordPolicy::MINIMUM_LENGTH, 'confirmed'],
            'new_password_confirmation' => ['required', 'string'],
        ];
    }
}
