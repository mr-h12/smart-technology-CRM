<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

use DateTimeImmutable;

/**
 * A `users` row as the administration screens see it.
 *
 * Deliberately **not** {@see \App\Modules\Identity\Domain\Authentication\Account}:
 * that one carries the password hash and `SEC-03`'s counters because the login
 * rules reason about them, and this one is the shape that gets serialised into
 * a response. Reusing it would put a credential one `json_encode` away from the
 * wire, which is the kind of accident a separate type makes impossible rather
 * than merely unlikely.
 *
 * `isHidden` is here and is never serialised. It exists so the directory can
 * assert §3.12 rule 6 held, not so a caller can filter on it.
 */
final readonly class AdministeredUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $roleId,
        public string $roleSlug,
        public string $roleName,
        public bool $isActive,
        public bool $isHidden,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
