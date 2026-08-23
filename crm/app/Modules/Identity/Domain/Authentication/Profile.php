<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * What `GET /auth/me` answers with — the person, their role, and the
 * `resource.action.scope` triples the **database** says they hold (`SEC-07`,
 * §3.12 rule 5).
 *
 * ── The list is not authorization ──────────────────────────────────────────
 *
 * `SEC-09` and §3.12 rule 1: this is what the SPA draws its menu from. Every
 * endpoint checks again, at the API and at the row (Point 2.3). A caller who
 * forges this array gets a different-looking menu and not one extra byte.
 *
 * ── What is deliberately absent ────────────────────────────────────────────
 *
 * No password hash and none of the security counters — `failed_login_attempts`,
 * `locked_until`, `is_hidden`. Coding Standards §9 keeps credential internals
 * out of browser responses, and `is_hidden` is §3.12 rule 6's own mechanism: a
 * field telling a client which account to omit is a field telling it the
 * account exists.
 */
final readonly class Profile
{
    /**
     * @param  array{id: string, slug: string, name: string}|null  $role
     * @param  list<string>  $permissions  sorted `resource.action.scope` triples
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $isActive,
        public ?array $role,
        public array $permissions,
        /**
         * §3.1 gives Super Admin access that is not in the matrix cells, so it
         * is stated rather than inferred from a full list — a client that only
         * saw "holds all 143 today" would silently mis-model the 144th.
         */
        public bool $unconditionalAccess,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->isActive,
            'role' => $this->role,
            'permissions' => $this->permissions,
            'unconditional_access' => $this->unconditionalAccess,
        ];
    }
}
