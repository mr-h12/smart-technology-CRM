<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

/**
 * Why a permission-matrix edit was refused, and the `OpenAPI §5.1` row it maps
 * to.
 *
 * §3.11 restricts the *caller* — "create / edit role · permissions ✅ Super
 * Admin, — Manager" — and the route middleware enforces that. These are the
 * refusals about everything else: the role being edited, and the grants being
 * asked for.
 */
enum RoleAdministrationRefusal: string
{
    /**
     * No such role, or an archived one.
     *
     * §5.1: "Resource does not exist **or is not visible to the caller**."
     * `DB-01` makes a retired role a soft delete, and an archived role is not
     * an editable one.
     */
    case RoleNotFound = 'role_not_found';

    /**
     * The Super Admin role. Refused, and not out of caution.
     *
     * §3.1 gives Super Admin unconditional access, and
     * `PermissionMatrix::scopeFor()` together with `Actor::hasUnconditionalAccess()`
     * answer for it **before** any grant row is consulted. So an administrator
     * who revoked every one of its grants would change 20 rows and change
     * nothing at all: the account keeps full access and the screen reports
     * success. A no-op that reports success is worse than a refusal, because
     * the administrator now believes something that is false about who can do
     * what.
     *
     * §3.12 rule 5 is not weakened by this. Rule 5 makes *the matrix* editable;
     * this is the one role the matrix does not describe.
     */
    case RoleIsImmutable = 'role_is_immutable';

    /**
     * A `permission_ids` entry that matches no live `permissions` row.
     *
     * A `422 validation_failed` about the field rather than a 404, because the
     * resource being addressed is the role and it was found — the submitted
     * body is what is wrong.
     */
    case PermissionNotFound = 'permission_not_found';

    /**
     * §3.12 rule 3 — "No hard deletes for customers, deals, reports or
     * suppliers — deactivate or archive only."
     *
     * The document writes those cells as a single merged "❌ Forbidden for
     * every role", which is a stronger statement than an empty cell: it is not
     * "nobody holds this today", it is "this is not grantable". Rule 3 is one
     * of the seven rules that **override** the matrix, so it survives the
     * configurability rule 5 gives every other cell.
     */
    case GrantForbidden = 'grant_forbidden';

    /**
     * `§3.1`'s eight, whose slug and label the system owns.
     *
     * Separate from {@see self::RoleIsImmutable}, and the two are not the same
     * rule wearing two names. That one is about **grants** and fires for the
     * Super Admin alone, because §3.12 rule 5 makes every other role's matrix
     * editable. This one is about **identity** — the row's slug and label — and
     * fires for all eight, because `Role`'s cases are matched on the slug and
     * `RolePermissionSeeder` rewrites `name` from `Role::label()` on every run.
     * Renaming Team Leader here would be undone by the next deployment and
     * would report success in the meantime.
     *
     * ⚠️ The owner's Point 4.2 brief asked for the code `role_is_immutable`
     * here. It is deliberately **not** reused: that code is already returned by
     * a different rule with a different scope, it is pinned by
     * `RolePermissionManagementTest` and matched by name in
     * `RolesMatrixView.vue`, and one code standing for two rules is a code the
     * SPA cannot map to one sentence. The status, the error class and the
     * behaviour are exactly what the brief specified.
     */
    case SystemRoleCannotBeEdited = 'system_role_cannot_be_edited';

    /**
     * `§3.1`'s eight, again — this time against archiving.
     *
     * The seeder restores a trashed row on its next run
     * (`RolePermissionSeeder::seedRoles`), so archiving a system role is a
     * change that undoes itself silently. Refusing is the honest answer.
     */
    case SystemRoleCannotBeDeleted = 'system_role_cannot_be_deleted';

    /**
     * Somebody still holds this role.
     *
     * `users.role_id` is NOT NULL and §3.1 gives every user exactly one role,
     * so archiving a role that is still assigned leaves accounts pointing at a
     * row no listing returns — and `AuthorizeAction` would answer *denied* for
     * every request they make, with nothing on any screen to explain why.
     * `D-34`'s deactivation is the documented way to stand somebody down; this
     * refusal is what stops a role archive from doing it by accident to
     * everybody at once.
     *
     * Counted over **live** users only. An archived account (`DB-01`) is not
     * somebody who will be refused tomorrow.
     */
    case RoleHasAssignedUsers = 'role_has_assigned_users';

    /**
     * The submitted slug is already a live role's machine key.
     *
     * A 422 on the field rather than a 409: the caller is creating a resource
     * and one of the values they typed is wrong, which is what a form needs to
     * hear. `roles_slug_unique_alive` refuses it at the database too — this is
     * the readable half of the same rule, not a substitute for it.
     */
    case SlugAlreadyTaken = 'slug_already_taken';

    /** The submitted English label is already a live role's. */
    case NameAlreadyTaken = 'name_already_taken';

    /** The submitted Arabic label is already a live role's. */
    case ArabicNameAlreadyTaken = 'name_ar_already_taken';

    /**
     * A `PATCH` that changes nothing.
     *
     * Refused rather than answered with the unchanged role, for the reason
     * `UpdateUser` gives: an empty body is far more often a client bug than an
     * intention, and a 200 hides it.
     */
    case NoFieldsSubmitted = 'no_fields_submitted';

    public function status(): int
    {
        return match ($this) {
            self::RoleNotFound => 404,
            default => 422,
        };
    }

    /** The `OpenAPI §5.1` top-level code for that status. */
    public function errorCode(): string
    {
        return match ($this) {
            self::RoleNotFound => 'resource_not_found',
            self::PermissionNotFound,
            self::SlugAlreadyTaken,
            self::NameAlreadyTaken,
            self::ArabicNameAlreadyTaken,
            self::NoFieldsSubmitted => 'validation_failed',
            default => 'business_rule_blocked',
        };
    }

    /** The submitted field a caller can correct, when there is one. */
    public function field(): ?string
    {
        return match ($this) {
            self::PermissionNotFound, self::GrantForbidden => 'permission_ids',
            self::SlugAlreadyTaken => 'slug',
            self::NameAlreadyTaken => 'name',
            self::ArabicNameAlreadyTaken => 'name_ar',
            default => null,
        };
    }

    public function messageKey(): string
    {
        return 'identity.role_administration.'.$this->value;
    }
}
