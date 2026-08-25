<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Authentication\PasswordChallenge;

/**
 * Where an outstanding `SEC-04` challenge lives, as the three operations the
 * flow needs.
 *
 * ── Why this is not a database table ───────────────────────────────────────
 *
 * A challenge is ephemeral by construction: it dies in fifteen minutes and is
 * destroyed the moment it is used. `DB-01` puts soft deletes and `DB-02` puts
 * four audit columns on every **business** table, and a row that is meant to
 * vanish fits neither — keeping it for ever, which is what soft delete means,
 * would preserve a credential hash long after the credential is gone.
 * `CACHE_STORE=redis` (§14.2, `.env.example` line 60, checked) gives the same
 * durability the session layer already runs on, plus a native TTL, so an
 * abandoned challenge disappears without a sweeper job.
 *
 * ⚠️ **What that costs, stated rather than hidden:** flushing the cache
 * invalidates every outstanding code. The user requests another one; nothing is
 * lost but a minute. And the challenge is **not** in `BK-01`'s backup set,
 * correctly — a fifteen-minute secret is not something to restore.
 *
 * Keyed by account, so one person holds at most one live challenge: requesting
 * a second code replaces the first, which is what every OTP flow does and what
 * a user pressing "resend" expects.
 */
interface PasswordChallengeStoreInterface
{
    /** Stores the challenge, replacing whatever that account held. */
    public function put(string $accountId, PasswordChallenge $challenge): void;

    public function find(string $accountId): ?PasswordChallenge;

    /** Single use: called on success, on expiry, and when the attempts run out. */
    public function forget(string $accountId): void;
}
