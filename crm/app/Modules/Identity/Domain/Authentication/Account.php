<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * A `users` row as the authentication rules see it.
 *
 * A value object and not the Eloquent model, for the reason
 * `deptrac.layers.yaml` exists: Domain may depend on nothing, and Application
 * may not reach Infrastructure — so the use case that decides whether somebody
 * may sign in cannot be handed a model. It is handed this.
 *
 * It carries exactly the five things `§9 Flow 0` reasons about — who, the hash,
 * `is_active` (`D-34`), the failure count and the lock (`SEC-03`) — and nothing
 * else. No role, no permissions: authenticating is not authorising, and a use
 * case that could see permissions would eventually check one.
 */
final readonly class Account
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        /** The stored hash. Never a plaintext password, in or out. */
        #[SensitiveParameter]
        public string $passwordHash,
        public bool $isActive,
        public int $failedLoginAttempts,
        public ?DateTimeImmutable $lockedUntil,
    ) {}
}
