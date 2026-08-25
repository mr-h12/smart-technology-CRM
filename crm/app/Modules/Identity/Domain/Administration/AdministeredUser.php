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
        /** The role's Arabic label, or null when it has none (§3.1's eight). */
        public ?string $roleNameAr,
        public bool $isActive,
        public bool $isHidden,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * The role label to show a reader in this locale.
     *
     * The same fallback {@see \App\Modules\Identity\Domain\RoleAdministration\RoleView::label()}
     * applies, and it is duplicated rather than shared because these are two
     * different reads of `roles` reached through two different contracts —
     * `RoleSummary` here, the full row there — and neither module may depend on
     * the other's value object. `CustomRoleManagementTest` asserts the two
     * screens answer alike for the same role.
     */
    public function roleLabel(string $locale): string
    {
        if ($locale === 'ar' && $this->roleNameAr !== null && $this->roleNameAr !== '') {
            return $this->roleNameAr;
        }

        return $this->roleName;
    }
}
