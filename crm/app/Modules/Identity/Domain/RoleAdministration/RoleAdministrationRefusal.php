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

    public function status(): int
    {
        return match ($this) {
            self::RoleNotFound => 404,
            self::PermissionNotFound => 422,
            default => 422,
        };
    }

    /** The `OpenAPI §5.1` top-level code for that status. */
    public function errorCode(): string
    {
        return match ($this) {
            self::RoleNotFound => 'resource_not_found',
            self::PermissionNotFound => 'validation_failed',
            default => 'business_rule_blocked',
        };
    }

    /** The submitted field a caller can correct, when there is one. */
    public function field(): ?string
    {
        return match ($this) {
            self::PermissionNotFound, self::GrantForbidden => 'permission_ids',
            default => null,
        };
    }

    public function messageKey(): string
    {
        return 'identity.role_administration.'.$this->value;
    }
}
