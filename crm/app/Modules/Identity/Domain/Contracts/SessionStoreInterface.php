<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

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
     * Revokes one session, and only if it belongs to that account.
     *
     * Idempotent: a session that is already gone is a logout that already
     * happened, not an error.
     */
    public function revoke(string $sessionId, string $accountId): void;

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
