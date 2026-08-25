<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\PasswordChallenge;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * {@see PasswordChallengeStoreInterface} over the cache — Redis in every
 * environment that matters (`CACHE_STORE=redis`, §14.2).
 *
 * ── Stored as three scalars, not as a serialised object ────────────────────
 *
 * `serialize()` on a domain object writes the class name into Redis, so
 * renaming or moving the class turns every live challenge into an unreadable
 * blob and a stream of `__PHP_Incomplete_Class`. Three primitives survive a
 * refactor, and the shape is checked on the way back out rather than trusted.
 *
 * ── The TTL is the expiry plus a margin, and the expiry still decides ──────
 *
 * Redis evicts the key on its own, which is what removes the need for a sweeper
 * job. But eviction is not a guarantee this code may lean on — a restart with
 * an old snapshot, a clock skew, a driver swapped for `array` in a test — so
 * {@see PasswordChallenge::hasExpired()} is still asked on every read. The TTL
 * is housekeeping; the timestamp is the rule.
 */
final class CachePasswordChallengeStore implements PasswordChallengeStoreInterface
{
    /** Namespaced so a cache shared with sessions and rate limits cannot collide. */
    private const PREFIX = 'identity.password_challenge.';

    /**
     * Seconds kept beyond the expiry, so a challenge that dies at second zero
     * is still readable long enough to be reported as *expired* rather than as
     * *no challenge outstanding*. Two different messages, and the honest one
     * needs the row to still be there.
     */
    private const GRACE_SECONDS = 60;

    public function __construct(private readonly Cache $cache) {}

    public function put(string $accountId, PasswordChallenge $challenge): void
    {
        $seconds = $challenge->expiresAt->getTimestamp() - time() + self::GRACE_SECONDS;

        $this->cache->put(
            self::PREFIX.$accountId,
            [
                'hash' => $challenge->codeHash,
                'expires_at' => $challenge->expiresAt->getTimestamp(),
                'failed_attempts' => $challenge->failedAttempts,
            ],
            max(1, $seconds),
        );
    }

    public function find(string $accountId): ?PasswordChallenge
    {
        /** @var mixed $stored */
        $stored = $this->cache->get(self::PREFIX.$accountId);

        if (! is_array($stored)) {
            return null;
        }

        $hash = $stored['hash'] ?? null;
        $expiresAt = $stored['expires_at'] ?? null;
        $attempts = $stored['failed_attempts'] ?? null;

        // A partial entry is treated as no entry. Reconstructing a challenge
        // from half a record would invent either an expiry or an attempt count,
        // and both inventions favour the attacker.
        if (! is_string($hash) || ! is_int($expiresAt) || ! is_int($attempts)) {
            return null;
        }

        return new PasswordChallenge(
            $hash,
            (new DateTimeImmutable)->setTimestamp($expiresAt),
            $attempts,
        );
    }

    public function forget(string $accountId): void
    {
        $this->cache->forget(self::PREFIX.$accountId);
    }
}
