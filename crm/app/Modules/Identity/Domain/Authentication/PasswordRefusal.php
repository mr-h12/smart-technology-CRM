<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * Why a password change was refused.
 *
 * Every case is a **422** in `OpenAPI §5.1`'s table — `validation_failed`,
 * "a well-formed request fails business or field validation" — and each
 * carries the field it belongs to, so the SPA can put the message under the
 * right input rather than in a banner.
 *
 * `CurrentPasswordIncorrect` is a 422 and not a 401 on purpose: the caller is
 * authenticated, the session is valid, and it is the submitted *field* that is
 * wrong. Returning 401 would tell a client that its token had died and send it
 * to the login screen, losing the form.
 */
enum PasswordRefusal: string
{
    /** The `current_password` field does not match the stored hash. */
    case CurrentPasswordIncorrect = 'current_password_incorrect';

    /** `D-28` — under 8 characters, or missing a letter or a digit. */
    case PolicyNotMet = 'password_policy_not_met';

    /** Changing a password to itself is not a change. */
    case SameAsCurrent = 'password_unchanged';

    /** The form field the message belongs under. */
    public function field(): string
    {
        return match ($this) {
            self::CurrentPasswordIncorrect => 'current_password',
            self::PolicyNotMet, self::SameAsCurrent => 'new_password',
        };
    }

    /**
     * The lang key for the message the caller sees.
     *
     * §14.2 wants Arabic and English from the first release and Coding
     * Standards §11 forbids a user-facing string literal, so the refusal
     * carries a key and Presentation resolves it in the request's locale.
     */
    public function messageKey(): string
    {
        return 'identity.password.'.$this->value;
    }
}
