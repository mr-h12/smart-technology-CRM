<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Impersonation;

/**
 * Why a Login As was refused, and the `OpenAPI §5.1` row it maps to.
 *
 * `SEC-10` restricts the *caller*; these are the refusals about everything
 * else — the target, and the state the caller is already in.
 */
enum ImpersonationRefusal: string
{
    /**
     * `SEC-10` — the caller is not the Super Admin.
     *
     * 403, and asked **again** behind the route's `permission:admin.login_as`
     * middleware. Not belt and braces: §3.12 rule 5 makes the matrix
     * configuration, so an administrator could in principle grant that row to
     * another role — and `SEC-10` is a **requirement**, not a configurable
     * cell. The middleware enforces the matrix; this enforces the sentence.
     */
    case CallerIsNotSuperAdmin = 'impersonation_forbidden';

    /**
     * No such account, or one §3.12 rule 6 hides.
     *
     * §5.1: "Resource does not exist **or is not visible to the caller**. Do
     * not reveal which case applies."
     */
    case TargetNotFound = 'target_not_found';

    /**
     * `D-34` · §10.1 — the account is deactivated.
     *
     * A documented rule blocking the action, which is exactly what §5.1's
     * `business_rule_blocked` is for, rather than a field that failed
     * validation. Signing in as somebody the system refuses to let sign in
     * would be a way around the one switch §10.1 provides.
     */
    case TargetSuspended = 'target_suspended';

    /** Impersonating yourself is the session you already have. */
    case TargetIsSelf = 'target_is_self';

    /**
     * Already inside an impersonation.
     *
     * Refused rather than nested, because a chain makes the audit trail
     * ambiguous: `impersonated_user_id` holds one value, and "who was really
     * acting" stops having a single answer the moment there are two links.
     */
    case AlreadyImpersonating = 'already_impersonating';

    /** Leaving an impersonation that is not happening. */
    case NotImpersonating = 'not_impersonating';

    public function status(): int
    {
        return match ($this) {
            self::CallerIsNotSuperAdmin => 403,
            self::TargetNotFound => 404,
            default => 422,
        };
    }

    /** The `OpenAPI §5.1` top-level code for that status. */
    public function errorCode(): string
    {
        return match ($this) {
            self::CallerIsNotSuperAdmin => 'permission_denied',
            self::TargetNotFound => 'resource_not_found',
            default => 'business_rule_blocked',
        };
    }

    public function messageKey(): string
    {
        return 'identity.impersonation.'.$this->value;
    }
}
