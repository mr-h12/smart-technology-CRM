<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

use App\Modules\Identity\Domain\Rbac\Role;

/**
 * §3.12 rule 7 and §3.11's create-user cell — which roles an administrator may
 * confer on somebody else.
 *
 * ── The document says this twice, and not identically ──────────────────────
 *
 * §3.11's row is an **allowlist**: "create user — ✅ any role (Super Admin) ·
 * ✅ (Out.Sup · Out.Sales · Sales · Procurement only) (Manager)". "Out.Sales"
 * is named separately there, so "Sales" in that list is Indoor Sales, and the
 * Manager's set is four roles.
 *
 * §3.12 rule 7 is a **denylist**: "The Manager may not create Manager, CEO or
 * Super Admin accounts." Subtracting those three from §3.1's eight leaves
 * five — the four above **plus Team Leader**.
 *
 * ⚠️ **The two disagree about Team Leader, and this class takes the narrower
 * reading.** §3.11's list carries the word "only", which is an exhaustive
 * enumeration; rule 7 names the roles that are obviously dangerous and does not
 * claim to be complete. Fail-closed is also the recoverable direction: a
 * Manager who cannot create a Team Leader asks the Super Admin, whereas a
 * Manager who could create one has already created it by the time anybody
 * reads the rule again. **This is an owner question, not a settled point** —
 * if the answer is "Team Leader too", it is one entry in
 * {@see self::MANAGER_MAY_CREATE}.
 *
 * ── Assignment, not only creation ──────────────────────────────────────────
 *
 * Rule 7 says "create". `UpdateUser` applies the same list, because moving an
 * existing account to a role is conferring that role — a rule that only guards
 * `POST` is a rule with a `PATCH` next to it.
 */
final class RoleAssignmentPolicy
{
    /**
     * §3.12 rule 7, verbatim: the three the Manager may never create.
     *
     * Kept as its own list even though {@see self::MANAGER_MAY_CREATE} already
     * excludes them, because it is a separate documented sentence and a test
     * asserts the allowlist honours it. Two statements of one rule that are
     * checked against each other beat one statement nobody can verify.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TO_MANAGER = [
        'manager',
        'ceo',
        'super_admin',
    ];

    /**
     * §3.11's four, in the document's order.
     *
     * @var list<string>
     */
    public const MANAGER_MAY_CREATE = [
        'outdoor_supervisor',
        'outdoor_sales',
        'indoor_sales',
        'procurement',
    ];

    /**
     * The roles this actor may confer.
     *
     * Super Admin gets "any role" — §3.11's own words — which is the same
     * unconditional access §3.1 gives it everywhere else. Every role that is
     * not Super Admin or Manager gets nothing: §3.11's "Others" column is `—`
     * for every row, so no other role administers users at all. The permission
     * middleware has normally refused them long before this is reached; this
     * returns the empty list rather than trusting that it did.
     *
     * @return list<Role>
     */
    public static function assignableBy(?Role $actor): array
    {
        if ($actor === null) {
            return [];
        }

        if ($actor->hasUnconditionalAccess()) {
            return Role::cases();
        }

        if ($actor !== Role::Manager) {
            return [];
        }

        return array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => in_array($role->value, self::MANAGER_MAY_CREATE, true),
        ));
    }

    public static function permits(?Role $actor, Role $target): bool
    {
        return in_array($target, self::assignableBy($actor), true);
    }

    /**
     * The same question about a slug read from the `roles` table.
     *
     * §3.12 rule 5 makes a ninth role a configuration change, so a slug outside
     * §3.1 is legitimate and has no rule 7 entry. Deciding either way for it
     * would be answering an authorisation question the document has not asked,
     * so only Super Admin's "any role" (§3.11) reaches it — which is the
     * fail-closed direction and the one an administrator can undo.
     */
    public static function permitsSlug(?Role $actor, string $targetSlug): bool
    {
        $target = Role::tryFrom($targetSlug);

        if (! $target instanceof Role) {
            return $actor?->hasUnconditionalAccess() === true;
        }

        return self::permits($actor, $target);
    }
}
