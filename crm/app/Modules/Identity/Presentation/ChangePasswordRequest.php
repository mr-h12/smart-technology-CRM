<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Authentication\VerificationCode;
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
 *
 * `verification_code` arrived with Point 3.3 and is **required**, not optional:
 * `SEC-04` says "mandatory", and a field a caller may omit is a control a
 * caller may skip.
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
            // SEC-04 · §9 Flow 0 step 2. `digits:` and not `size:` — `size` on a
            // string counts characters and would accept "abcdef", which the use
            // case then rejects as a wrong code and charges an attempt for.
            'verification_code' => ['required', 'string', 'digits:'.VerificationCode::LENGTH],
        ];
    }
}
