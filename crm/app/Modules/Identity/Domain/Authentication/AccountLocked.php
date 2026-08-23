<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;

/**
 * `SEC-03`'s second half — "+ Super Admin notification" — as a domain event.
 *
 * An event rather than a mail call inside the use case, because `CLAUDE.md`
 * puts cross-cutting work behind "interfaces and domain events" and because
 * locking an account and telling somebody about it fail independently: a mail
 * server that is down must not undo the lock.
 *
 * Carries the identity of the account and nothing else. No model, no email
 * body, no framework type — a listener that needs more looks it up.
 */
final readonly class AccountLocked
{
    public function __construct(
        public string $userId,
        public string $userName,
        public string $userEmail,
        public DateTimeImmutable $lockedUntil,
        public ?string $ip,
    ) {}
}
