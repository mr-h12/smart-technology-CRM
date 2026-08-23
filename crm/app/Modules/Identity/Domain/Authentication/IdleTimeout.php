<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * `D-29` — "Session expires after **8 hours idle**" — and `SEC-05`'s
 * "8-hour session timeout".
 *
 * **Idle, not absolute.** The clock restarts on every authenticated request,
 * which is why `user_sessions.last_activity_at` is written on each one. A
 * fixed eight hours from login would log a working day's user out mid-sentence,
 * and neither `D-29` nor `SEC-05` says that.
 *
 * **Enforced here rather than left to the session store.** `SESSION_DRIVER` is
 * Redis and Redis has its own TTL, but that TTL is infrastructure
 * configuration: it can be changed by an operator, it differs per environment,
 * and it says nothing in a test. `D-29` is a business rule, so it is a rule in
 * the domain with a row to check it against.
 */
final class IdleTimeout
{
    /** `D-29`. */
    public const HOURS = 8;

    /**
     * The instant before which activity is too old to keep a session alive.
     *
     * Exposed separately from {@see hasExpired()} so a sweep can push the
     * comparison into SQL — `UserSession::scopeIdleSince()` takes exactly this
     * — instead of loading every row to ask each one.
     */
    public static function boundary(DateTimeInterface $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now)
            ->modify('-'.self::HOURS.' hours');
    }

    /**
     * Whether a session last active at `$lastActivity` has gone idle.
     *
     * Strictly older than the boundary expires; exactly eight hours does not.
     * The boundary belongs to the live side because `D-29` says the session
     * expires *after* eight hours, not on the eighth.
     */
    public static function hasExpired(DateTimeInterface $lastActivity, DateTimeInterface $now): bool
    {
        return $lastActivity->getTimestamp() < self::boundary($now)->getTimestamp();
    }
}
