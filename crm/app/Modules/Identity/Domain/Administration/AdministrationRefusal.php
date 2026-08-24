<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

/**
 * Why a user-administration command was refused, and the `OpenAPI §5.1` row it
 * maps to.
 *
 * The value is the stable code that goes inside `error.details[].code` — §5.1
 * closes the set of HTTP statuses and top-level codes, so this is where the
 * actual reason survives.
 */
enum AdministrationRefusal: string
{
    /**
     * §3.12 rule 7 / §3.11 — the actor may not confer this role.
     *
     * 422 and not 403: the caller *is* permitted to administer users, and it is
     * the submitted `role_id` that is unacceptable. A 403 here would tell the
     * SPA the whole endpoint was refused and send the user away from a form
     * they can still complete correctly.
     */
    case RoleNotAssignable = 'role_not_assignable';

    /**
     * No such user, or one this caller may not see.
     *
     * §5.1: "Resource does not exist or is not visible to the caller. Do not
     * reveal which case applies." §3.12 rule 6's hidden Super Admin arrives
     * here, and that is the entire point — a 403 would confirm the account.
     */
    case UserNotFound = 'user_not_found';

    /** The address belongs to a live account already (the partial unique index). */
    case EmailAlreadyTaken = 'email_already_taken';

    /** `D-28`, asked of the initial password the administrator set. */
    case PasswordPolicyNotMet = 'password_policy_not_met';

    /** The submitted `role_id` matches no live role. */
    case RoleNotFound = 'role_not_found';

    public function status(): int
    {
        return match ($this) {
            self::UserNotFound => 404,
            default => 422,
        };
    }

    /** The `OpenAPI §5.1` top-level code for that status. */
    public function errorCode(): string
    {
        return match ($this) {
            self::UserNotFound => 'resource_not_found',
            default => 'validation_failed',
        };
    }

    /**
     * The submitted field this is about, or null when it is about the resource.
     *
     * Matches the shape a Form Request failure produces, so the SPA handles one
     * envelope rather than two.
     */
    public function field(): ?string
    {
        return match ($this) {
            self::RoleNotAssignable, self::RoleNotFound => 'role_id',
            self::EmailAlreadyTaken => 'email',
            self::PasswordPolicyNotMet => 'password',
            self::UserNotFound => null,
        };
    }

    public function messageKey(): string
    {
        return 'identity.administration.'.$this->value;
    }
}
