<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * `SEC-04` step one — a code was issued and has to reach an inbox.
 *
 * An event with a listener, the same shape `AccountLocked` uses, and for the
 * same reason: `CLAUDE.md` puts cross-cutting work behind domain events, and
 * Application may not reach the mailer.
 *
 * ── ⚠️ This event carries a live secret ────────────────────────────────────
 *
 * `$code` is the plaintext the person types back in. It has to be here — the
 * store keeps only a hash, so nothing downstream could recover it — but that
 * makes this the one event in the module that must never be **queued**,
 * **serialised**, or **logged**. Its listener is registered synchronously in
 * `AppServiceProvider`, and `AUD-05`'s structured logging never receives an
 * event object. If a later change queues listeners globally, this event needs
 * an exemption before that change lands, not after.
 */
final readonly class PasswordChallengeIssued
{
    public function __construct(
        public string $userId,
        public string $userName,
        public string $userEmail,
        /** ⚠️ Plaintext. See the class comment before passing this anywhere. */
        #[SensitiveParameter]
        public string $code,
        public DateTimeImmutable $expiresAt,
    ) {}
}
