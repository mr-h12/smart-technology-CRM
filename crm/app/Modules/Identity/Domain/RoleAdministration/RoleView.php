<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;

/**
 * One `roles` row plus the triples it grants (`SEC-07`).
 *
 * `isSystem` is `§3.1`'s eight — the roles the seeder writes with
 * `is_system = true`. It is carried to the screen because an administrator
 * deleting or renaming one is a different question from an administrator
 * changing what one may do, and only the second is what this point implements.
 */
final readonly class RoleView
{
    /** @param list<PermissionView> $permissions */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public bool $isSystem,
        public ?string $description,
        public array $permissions,
    ) {}

    /**
     * The `§3.1` role this row stands for, or null for one an administrator
     * added later (`§3.12` rule 5 makes that a configuration change).
     */
    public function name(): ?RoleName
    {
        return RoleName::tryFrom($this->slug);
    }

    /**
     * True for the account `§3.1` gives unconditional access and `§3.12` rule 6
     * hides.
     *
     * Asked through the typed role rather than by comparing this class's slug
     * to a literal, so the definition of "unconditional" stays in one place.
     */
    public function hasUnconditionalAccess(): bool
    {
        return $this->name()?->hasUnconditionalAccess() === true;
    }

    /**
     * The granted triples, sorted, as the audit row records them.
     *
     * Sorted because `AUD-02` stores old and new values and an auditor compares
     * them: two identical grant sets that differ only in row order would read
     * as a change that never happened.
     *
     * @return list<string>
     */
    public function triples(): array
    {
        $triples = [];

        foreach ($this->permissions as $permission) {
            $triples[] = $permission->triple();
        }

        sort($triples);

        return $triples;
    }
}
