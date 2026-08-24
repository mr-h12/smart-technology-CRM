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
     * §9 Flow 9's "account created automatically". `AUD-01` covers create;
     * the row records name, address and role — never the initial password.
     */
    public const USER_CREATED = 'USER_CREATED';

    /** `AUD-01`'s update, with the fields that actually changed. */
    public const USER_UPDATED = 'USER_UPDATED';

    /**
     * §3.12 rule 4 names "role change" as a **mandatory** audit entry in its
     * own right. It is written alongside `USER_UPDATED` rather than folded into
     * it, because an auditor answering "who was promoted last quarter" filters
     * on the event column, and a role change buried in the diff of a generic
     * update is a row that query never returns.
     */
    public const ROLE_CHANGED = 'ROLE_CHANGED';

    /** §3.12 rule 4's "account deactivation", and `D-34`'s switch. */
    public const USER_DEACTIVATED = 'USER_DEACTIVATED';

    /**
     * The inverse. Not named by rule 4 — the list is a floor, not a ceiling,
     * and an account coming *back* is at least as interesting as one going
     * away.
     */
    public const USER_ACTIVATED = 'USER_ACTIVATED';

    /**
     * A refusal that was neither wrong credentials nor a lock: `D-34`'s
     * deactivated account presenting a password that was in fact correct.
     * Worth a row of its own — it is the only signal that a suspended person is
     * still trying to work.
     */
    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';
}
