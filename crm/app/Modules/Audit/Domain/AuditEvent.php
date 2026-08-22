<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use InvalidArgumentException;

/**
 * The `event` of an audit row (`AUD-02`) — a validated name, not a free string.
 *
 * **Named constructors for the nine `§3.12` rule 4 requires**, because a
 * misspelled mandatory event is a row that no audit query will ever find, and
 * `AUD-03` means it cannot be corrected afterwards. `of()` stays open for the
 * vocabulary every later module brings; what it does not stay open to is a
 * shape the column or a log line cannot carry.
 *
 * Not an enum: an enum would have to name every event in the system in advance,
 * and Modules 3 to 14 each add their own. Not a plain string either — that is
 * the version with no floor at all.
 */
final readonly class AuditEvent
{
    /**
     * Upper snake case, at most 64 characters — the width of `audit_log.event`.
     *
     * The `D` modifier is load-bearing rather than decorative: without it PCRE's
     * `$` also matches before a trailing newline, so "LOGIN_AS\n" would pass
     * this very pattern and put a line break into a permanent log record.
     */
    public const SHAPE = '/^[A-Z][A-Z0-9_]{0,63}$/D';

    private function __construct(public string $value) {}

    public static function of(string $name): self
    {
        if (preg_match(self::SHAPE, $name) !== 1) {
            // The message quotes nothing back: this value can reach a log line,
            // and a rejected value is exactly the one least worth echoing.
            throw new InvalidArgumentException(
                'An audit event must be upper snake case and at most 64 characters (§3.12, AUD-02).',
            );
        }

        return new self($name);
    }

    // ── §3.12 rule 4: the nine that must always be audited ──────────────────

    public static function taxChanged(): self
    {
        return new self('TAX_CHANGED');
    }

    public static function marginChanged(): self
    {
        return new self('MARGIN_CHANGED');
    }

    public static function customerReassigned(): self
    {
        return new self('CUSTOMER_REASSIGNED');
    }

    public static function roleChanged(): self
    {
        return new self('ROLE_CHANGED');
    }

    public static function loginAs(): self
    {
        return new self('LOGIN_AS');
    }

    public static function archiveRestored(): self
    {
        return new self('ARCHIVE_RESTORED');
    }

    public static function fxRateChanged(): self
    {
        return new self('FX_RATE_CHANGED');
    }

    public static function accountDeactivated(): self
    {
        return new self('ACCOUNT_DEACTIVATED');
    }

    public static function selfApproval(): self
    {
        return new self('SELF_APPROVAL');
    }
}
