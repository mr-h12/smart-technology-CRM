<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Authentication\DeviceSession;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use DateTimeImmutable;

/**
 * `SEC-05`'s device list, as the two operations the use cases need.
 *
 * Not a session *store* in Laravel's sense — `SESSION_DRIVER` is Redis and owns
 * nothing here. These rows exist so a device can be listed and revoked, and
 * revoking is the soft delete `DB-01` already requires: there is no second flag
 * to fall out of step with it.
 */
interface SessionStoreInterface
{
    /**
     * Opens a session and returns its id.
     *
     * `$fingerprint` is the token's SHA-256 digest, never the token
     * (Coding Standards §9).
     */
    public function open(
        string $accountId,
        string $fingerprint,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): string;

    /**
     * `SEC-10` — opens a session that **belongs to** `$accountId` but is being
     * driven by `$impersonatorId`.
     *
     * A separate method rather than a nullable argument on {@see self::open()}:
     * a login and a Login As are different operations with different audit
     * obligations, and a parameter that defaults to null is one a future caller
     * forgets to pass. This signature cannot be reached by accident.
     */
    public function openAs(
        string $accountId,
        string $impersonatorId,
        string $fingerprint,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): string;

    /** How many live sessions this account is currently impersonating through. */
    public function impersonationsBy(string $impersonatorId): int;

    /**
     * Revokes one session, and only if it belongs to that account.
     *
     * Idempotent: a session that is already gone is a logout that already
     * happened, not an error.
     */
    public function revoke(string $sessionId, string $accountId): void;

    /**
     * `SEC-05`'s active device list, for the person who owns the account.
     *
     * ⚠️ **Impersonation rows are excluded, and that is a rule rather than a
     * filter.** A Login As session belongs to the Super Admin who is driving
     * it, and §3.1 makes that account "completely hidden from all users" — a
     * device the owner did not sign in on, appearing in their own list, names
     * the hidden administrator by implication. `SEC-10`'s mandatory audit is
     * the control on Login As; this list is not.
     *
     * `$currentSessionId` is the caller's own session, so exactly one row can
     * come back marked. Null when the caller has no resolvable session id,
     * which marks none rather than guessing.
     *
     * @return ReferencePage<DeviceSession>
     */
    public function devicesFor(
        string $accountId,
        ?string $currentSessionId,
        ReferenceListCriteria $criteria,
    ): ReferencePage;

    /**
     * Revokes one device of this account, and reports whether there was one.
     *
     * False means no live, non-impersonation row with that id belongs to this
     * account — which the use case turns into `SessionNotFound`. Distinct from
     * {@see self::revoke()}, which is idempotent because signing out twice is
     * not an error; asking to revoke a device that is not yours is.
     */
    public function revokeDevice(string $sessionId, string $accountId): bool;

    /**
     * `SEC-05`'s force logout — every device except the one calling.
     *
     * Excludes impersonation rows for the reason {@see self::devicesFor()}
     * gives: a session this list never showed must not be silently taken down
     * by a button whose label counts what it revoked.
     *
     * @return int how many were revoked
     */
    public function revokeOtherDevices(string $accountId, string $currentSessionId): int;

    /**
     * Revokes every session this account holds, and returns how many.
     *
     * §9 Flow 0 ends the password-change flow with "**log in again**", so this
     * takes down the calling device too. Anything narrower leaves the one
     * session an attacker is most likely to be holding — the one that is live
     * right now — and a password change whose purpose is to evict somebody
     * that does not evict them is worse than none, because the user believes
     * it worked.
     *
     * The count is returned so `AUD-01`'s row can record the blast radius.
     */
    public function revokeAllFor(string $accountId): int;
}
