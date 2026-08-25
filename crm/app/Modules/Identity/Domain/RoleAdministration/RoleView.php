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
        public ?string $nameAr,
        public bool $isSystem,
        public ?string $description,
        public array $permissions,
    ) {}

    /**
     * The label to show a reader in this locale.
     *
     * `CLAUDE.md` requires every screen to work in Arabic and English, and a
     * role's label is **data** rather than a lang key — §3.12 rule 5 makes a
     * ninth role a runtime configuration change, so there is no lang file its
     * name could ever live in and nobody to translate it afterwards.
     *
     * ⚠️ **`§3.1`'s eight answer English in both languages**, because `nameAr`
     * is null on every one of them: the master documentation names them in
     * English only, and writing Arabic for them here would be inventing
     * documentation rather than reading it. The fallback is what keeps that gap
     * from rendering as an empty cell.
     *
     * Resolved in Domain and handed the locale, rather than read from the
     * framework: this class may depend on nothing (`deptrac.layers.yaml` gives
     * Domain an empty ruleset), and a rule that reaches for a global is a rule
     * a second entry point resolves differently.
     */
    public function label(string $locale): string
    {
        if ($locale === 'ar' && $this->nameAr !== null && $this->nameAr !== '') {
            return $this->nameAr;
        }

        return $this->name;
    }

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
