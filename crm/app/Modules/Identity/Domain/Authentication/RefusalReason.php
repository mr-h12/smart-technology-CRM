<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * Why an authentication attempt was refused, in the vocabulary
 * `OpenAPI §5.1` already fixed.
 *
 * The HTTP status and `error.code` are **not free choices** — §5.1 is a closed
 * table, so each case names a row of it rather than inventing a pair. The
 * specific reason travels in `details[].code`, which is exactly what §5.1 says
 * that field is for: "specific stable codes inside `details` for expected
 * rules".
 */
enum RefusalReason: string
{
    /** Wrong email, wrong password, or an account that no longer exists. */
    case InvalidCredentials = 'invalid_credentials';

    /** `D-34` · §10.1 — deactivated, never deleted. */
    case AccountSuspended = 'account_suspended';

    /** `SEC-03` — five consecutive failures. */
    case AccountLocked = 'account_locked';

    /** `D-29` idle, revoked by `SEC-05` force-logout, or never valid. */
    case SessionInvalid = 'session_invalid';

    /** The row of `OpenAPI §5.1` this refusal belongs to. */
    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials, self::SessionInvalid => 401,
            self::AccountSuspended => 403,
            self::AccountLocked => 423,
        };
    }

    /**
     * The stable `error.code` from `OpenAPI §5.1`.
     *
     * `AccountSuspended` maps to `permission_denied` because §5.1 offers no
     * other 403 code, and the table is closed. The precise reason is not lost:
     * it is this enum's own value, carried in `details[].code`.
     */
    public function errorCode(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'authentication_required',
            self::SessionInvalid => 'session_invalid',
            self::AccountSuspended => 'permission_denied',
            self::AccountLocked => 'account_locked',
        };
    }

    /**
     * The lang key for the message the caller sees.
     *
     * §14.2 requires Arabic and English from the first release and Coding
     * Standards §11 forbids a user-facing string literal, so the refusal
     * carries a key and the presentation layer resolves it in the request's
     * locale. §10.1 fixes one of these messages word for word — "Account
     * suspended, please contact administration".
     */
    public function messageKey(): string
    {
        return 'identity.refusal.'.$this->value;
    }
}
