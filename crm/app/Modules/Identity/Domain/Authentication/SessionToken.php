<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * The credential a signed-in client presents, and the fingerprint the server
 * keeps instead of it.
 *
 * ── Why a bearer credential at all ─────────────────────────────────────────
 *
 * `OpenAPI §3.1` allows either "an authenticated, active user session **or an
 * equivalent server-issued bearer credential**", and nothing in the master
 * documentation picks between them. This module picks the bearer credential,
 * for four reasons that are all documented requirements rather than taste:
 * `AP-07` and `D-67` put one API under both the desktop SPA and the field PWA,
 * and a non-ambient credential behaves identically for both; `SEC-05` wants an
 * active-device list and force-logout, which is a row per device and a
 * revocation that is a row change; `D-29`'s idle rule then belongs to us
 * (`IdleTimeout`) rather than to a store's TTL; and `SEC-13`'s CSRF surface
 * does not exist for a credential the browser never attaches by itself.
 *
 * ⚠️ **The trade-off, stated rather than hidden.** A token the SPA has to hold
 * is reachable by script in a way an `HttpOnly` cookie is not, so an XSS defect
 * would leak it. What limits the damage is that it is `SEC-05`-revocable, dies
 * after eight hours idle (`D-29`), and is stored here only as a digest — a
 * database disclosure yields nothing that can be presented. **This is an
 * implementation choice inside a documented allowance, not a recorded
 * decision; it is flagged for the owner to record.**
 *
 * ── Why only the digest is stored ──────────────────────────────────────────
 *
 * `user_sessions.session_id` holds `hash('sha256', token)`, never the token.
 * Coding Standards §9 — "never expose … session tokens" — and the same reason
 * `users.password` is hashed: a table anyone can read must not contain a
 * credential anyone can replay. SHA-256 unsalted is correct *here* and would be
 * wrong for a password: the input is 256 bits of `random_bytes`, so there is no
 * dictionary to attack and no need for a slow function on a per-request path.
 */
final readonly class SessionToken
{
    /** 32 bytes → 64 hex characters. Well inside `session_id`'s VARCHAR(255). */
    public const BYTES = 32;

    /** The hex length a presented token must have to be worth a lookup. */
    public const LENGTH = self::BYTES * 2;

    private function __construct(public string $value) {}

    /** A new credential, from the CSPRNG and nothing else. */
    public static function issue(): self
    {
        return new self(bin2hex(random_bytes(self::BYTES)));
    }

    /**
     * A token as a client presented it.
     *
     * The shape is checked before anything is done with the value, because the
     * next thing that happens to it is a database lookup and an unbounded
     * attacker-controlled string is not something to hand a query — nor to put
     * in a log line, which is why the message quotes nothing back.
     */
    public static function fromPresented(#[SensitiveParameter] string $presented): self
    {
        if (preg_match('/^[0-9a-f]{'.self::LENGTH.'}$/D', $presented) !== 1) {
            throw new InvalidArgumentException('A session token is '.self::LENGTH.' lowercase hex characters.');
        }

        return new self($presented);
    }

    /** What `user_sessions.session_id` stores. Never the token itself. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->value);
    }
}
