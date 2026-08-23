<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * `SEC-03` — "Lockout after 5 failures + Super Admin notification", and §9
 * Flow 0 — "after 5 failures → account locked + Super Admin notified".
 *
 * Framework-free on purpose: `deptrac.layers.yaml` gives Domain an empty
 * ruleset, and the threshold is the one number in this module that a defect
 * would make invisible — an off-by-one here reads as a working login for the
 * six months before anyone counts.
 *
 * ⚠️ **The documentation gives no lockout *duration*.** §9 Flow 0 and `SEC-03`
 * both stop at "locked", and neither says whether the lock lifts on its own,
 * how long it lasts, or who clears it. The `locked_until` column exists because
 * Point 1.2's approved schema has one, which presupposes an expiry. So the
 * duration is a **configured** value passed in from outside rather than a
 * constant invented here, and the gap is recorded rather than papered over.
 */
final class LockoutPolicy
{
    /** `SEC-03`: five. Not four, not "a few". */
    public const MAX_ATTEMPTS = 5;

    /**
     * Whether the count of *consecutive* failures now standing against an
     * account has reached the threshold.
     *
     * Takes the count after the current failure has been added, because the
     * alternative — "would this one make it five" — is the phrasing that
     * produces an off-by-one every time somebody moves the increment.
     */
    public static function shouldLock(int $consecutiveFailures): bool
    {
        if ($consecutiveFailures < 0) {
            throw new InvalidArgumentException('A failure count cannot be negative.');
        }

        return $consecutiveFailures >= self::MAX_ATTEMPTS;
    }

    /**
     * Whether a lock recorded as lifting at `$lockedUntil` is still in force.
     *
     * A null column means never locked. The comparison is `>` rather than
     * `>=`, so the instant the lock expires the account is usable — a boundary
     * that matters because `J-xx`-style clock ticks land exactly on it.
     */
    public static function isLocked(?DateTimeInterface $lockedUntil, DateTimeInterface $now): bool
    {
        return $lockedUntil !== null && $lockedUntil->getTimestamp() > $now->getTimestamp();
    }

    /**
     * When a lock taken out now would lift.
     *
     * `$minutes` is configuration (`AP-08`, §3.12 rule 5 — limits are not code
     * constants). Zero or less is refused rather than treated as "no lock":
     * silently not locking is the failure mode `SEC-03` exists to prevent.
     */
    public static function lockedUntil(DateTimeInterface $now, int $minutes): DateTimeImmutable
    {
        if ($minutes < 1) {
            throw new InvalidArgumentException(
                'A lockout duration must be at least one minute (SEC-03).',
            );
        }

        return DateTimeImmutable::createFromInterface($now)->modify("+{$minutes} minutes");
    }
}
