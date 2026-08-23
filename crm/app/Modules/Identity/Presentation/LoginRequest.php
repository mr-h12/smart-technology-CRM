<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `SEC-12` — backend validation on every field — at the boundary, which is
 * where Coding Standards puts it.
 *
 * **`D-28` is not applied here.** The eight-character, letters-and-numbers rule
 * governs the password a user *sets*; applying it to one they *present* would
 * mean rejecting a login before checking it, which tells an unauthenticated
 * caller the shape of the secret. The build plan's criterion — "a password
 * under 8 characters or digits only → rejected with a clear message" — is about
 * `change-password`, and `PasswordPolicy` is already the one place that rule
 * lives.
 */
final class LoginRequest extends FormRequest
{
    /** No sign-up, no gate: `SEC-01` means the endpoint itself is the surface. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // 255 is `users.email`'s width. Anything longer cannot match a row,
            // so it is refused before it becomes a query.
            'email' => ['required', 'string', 'email', 'max:255'],
            // No max: a passphrase is a good password, and the hasher takes
            // whatever arrives. `string` keeps an array out of `Hash::check`.
            'password' => ['required', 'string'],
        ];
    }
}
