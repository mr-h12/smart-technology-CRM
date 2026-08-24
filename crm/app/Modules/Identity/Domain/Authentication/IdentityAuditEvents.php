<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * The `audit_log.event` names this module writes.
 *
 * `AUD-01` asks for a comprehensive audit and `SEC-16` names one of these
 * outright — "IP blacklist + **failed login log**". They are constants in one
 * place because `AUD-03` makes every row permanent: a misspelling here is a
 * record no audit query will ever find and no correction can ever fix.
 *
 * None of the four is in §3.12 rule 4's mandatory nine, which is why they are
 * built with `AuditEvent::of()` — the constructor that section's own comment
 * describes as "open for the vocabulary every later module brings".
 */
final class IdentityAuditEvents
{
    /** A session was issued. */
    public const LOGIN_SUCCEEDED = 'LOGIN_SUCCEEDED';

    /** `SEC-16`'s failed login log. */
    public const LOGIN_FAILED = 'LOGIN_FAILED';

    /** `SEC-03`. The fifth consecutive failure. */
    public const ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';

    /** A session was surrendered by its owner. */
    public const LOGOUT = 'LOGOUT';

    /**
     * `SEC-04`'s event. The row records **that** the password changed and how
     * many sessions it took down — never the old hash and never the new one.
     * `AUD-03` makes the row permanent, and a permanent record of a credential
     * is a credential with a longer life than the account.
     */
    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    /**
     * A refusal that was neither wrong credentials nor a lock: `D-34`'s
     * deactivated account presenting a password that was in fact correct.
     * Worth a row of its own — it is the only signal that a suspended person is
     * still trying to work.
     */
    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';
}
