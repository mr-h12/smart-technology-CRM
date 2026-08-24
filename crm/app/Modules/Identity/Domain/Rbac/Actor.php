<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * The caller, as the authorisation engine sees them.
 *
 * Three fields and no more. §3.1 gives every user exactly one role, so a
 * decision needs the role's identity (to read its grants) and its slug (to ask
 * whether §3.1 exempts it from the matrix). The user's own id is here because
 * `SEC-08`'s row-level scopes are answered against it — "own" means *this* id.
 *
 * Not the Eloquent model: `deptrac` gives Domain an empty ruleset (`D-77`), and
 * an engine that could see a model would eventually reach past its own question.
 */
final readonly class Actor
{
    public function __construct(
        public string $userId,
        public string $roleId,
        public string $roleSlug,
    ) {}

    /**
     * The typed §3.1 role, or null for one an administrator added later.
     *
     * Null is not an error: §3.12 rule 5 puts the matrix in the database, so a
     * ninth role is a configuration change. It simply has no unconditional
     * access, because §3.1 grants that to exactly one role.
     */
    public function role(): ?Role
    {
        return Role::tryFrom($this->roleSlug);
    }

    /** §3.1 — true only for Super Admin. */
    public function hasUnconditionalAccess(): bool
    {
        return $this->role()?->hasUnconditionalAccess() === true;
    }
}
