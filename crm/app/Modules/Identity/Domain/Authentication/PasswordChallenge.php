<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;

/**
 * An outstanding `SEC-04` challenge: the hash of a code, when it dies, and how
 * many wrong guesses it has already absorbed.
 *
 * ── The stored value is a hash, and not a SHA-256 one ──────────────────────
 *
 * {@see SessionToken::fingerprint()} stores `sha256` of a session token and is
 * right to: that token is 32 CSPRNG bytes, so reversing the digest means
 * searching 2^256. **A six-digit code is a search space of one million**, which
 * a laptop exhausts against SHA-256 in well under a second. So this carries a
 * *password* hash — Argon2id or bcrypt, whichever `hashing.driver` names —
 * where each candidate costs tens of milliseconds and the million-guess sweep
 * costs hours instead of an eyeblink. The two cases look alike and are not, and
 * copying the session token's approach here would have been the quiet defect.
 *
 * ── The attempt counter is part of the control, not a nicety ───────────────
 *
 * Without it, fifteen minutes of automated guessing gets a meaningful fraction
 * of a million tries. The counter is why six digits is enough: the challenge
 * dies after a handful of wrong codes, and the caller has to request another
 * one — which is itself rate-limited.
 */
final readonly class PasswordChallenge
{
    public function __construct(
        /** Argon2id/bcrypt of the code. Never the code. */
        #[SensitiveParameter]
        public string $codeHash,
        public DateTimeImmutable $expiresAt,
        public int $failedAttempts = 0,
    ) {}

    public static function issuedAt(
        #[SensitiveParameter] string $codeHash,
        DateTimeInterface $now,
        int $ttlMinutes,
    ): self {
        if ($ttlMinutes < 1) {
            // A zero or negative lifetime is a challenge that has expired
            // before it is mailed. Refusing beats issuing one nobody can use.
            throw new \InvalidArgumentException('A challenge must live at least one minute.');
        }

        return new self(
            $codeHash,
            DateTimeImmutable::createFromInterface($now)->modify('+'.$ttlMinutes.' minutes'),
            0,
        );
    }

    /**
     * Strictly after the expiry instant.
     *
     * `>` rather than `>=`, matching {@see LockoutPolicy::isLocked()}: a code
     * presented in the same second it expires is still good. The boundary is
     * arbitrary either way; being consistent with the other one in this module
     * is not.
     */
    public function hasExpired(DateTimeInterface $now): bool
    {
        return $now->getTimestamp() > $this->expiresAt->getTimestamp();
    }

    public function withFailure(): self
    {
        return new self($this->codeHash, $this->expiresAt, $this->failedAttempts + 1);
    }

    public function isExhausted(int $maxAttempts): bool
    {
        return $this->failedAttempts >= $maxAttempts;
    }
}
