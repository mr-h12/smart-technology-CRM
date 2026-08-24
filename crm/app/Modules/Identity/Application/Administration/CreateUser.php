<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\AdministrationRefusal;
use App\Modules\Identity\Domain\Administration\RoleAssignmentPolicy;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\RoleSummary;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Rbac\Actor;
use App\Modules\Identity\Domain\Rbac\Role;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use SensitiveParameter;

/**
 * §9 Flow 9 — "The Manager adds: name · email · role … account created
 * automatically".
 *
 * ── The order of the checks ────────────────────────────────────────────────
 *
 * 1. **The role exists** — a `role_id` pointing nowhere is answered before
 *    anything is asked about it.
 * 2. **§3.12 rule 7 / §3.11** — may this actor confer that role? Asked of the
 *    actor's *current* role read from the database, never of anything the
 *    request said about itself.
 * 3. **`D-28`** on the initial password, from the one class that owns the rule.
 * 4. **The address is free**, re-asked inside the transaction because the
 *    Form Request's `unique` rule and the `INSERT` are two moments.
 *
 * ── What the request may not decide ────────────────────────────────────────
 *
 * `is_hidden` is **derived from the role** ({@see Role::isHidden()}), exactly as
 * `UserSeeder` derives it, and is never read from the payload. §3.12 rule 6 is
 * a property of being the Super Admin, and a flag a client could set is a flag
 * a client could clear.
 *
 * ⚠️ **Flow 9 also says "and credentials emailed", and this does not send
 * mail.** The administrator sets the initial password and must convey it out of
 * band. Recorded as an open gap, alongside `SEC-04`'s verification code, rather
 * than quietly dropped from the flow.
 */
final readonly class CreateUser
{
    public function __construct(
        private ConnectionInterface $connection,
        private UserDirectoryInterface $users,
        private PermissionRepositoryInterface $permissions,
        private Hasher $hasher,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws UserAdministrationRefused */
    public function handle(
        string $actorId,
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
        string $roleId,
    ): AdministeredUser {
        $role = $this->users->findRole($roleId);

        if (! $role instanceof RoleSummary) {
            throw UserAdministrationRefused::because(AdministrationRefusal::RoleNotFound);
        }

        $this->assertMayConfer($actorId, $role);

        if (! PasswordPolicy::isSatisfiedBy($password)) {
            throw UserAdministrationRefused::because(AdministrationRefusal::PasswordPolicyNotMet);
        }

        $hash = $this->hasher->make($password);

        // §3.12 rule 6, derived rather than accepted. Role::tryFrom is null for
        // a ninth role an administrator added (rule 5), and a role nobody
        // documented as hidden is not hidden.
        $isHidden = Role::tryFrom($role->slug)?->isHidden() === true;

        return $this->connection->transaction(function () use ($name, $email, $hash, $role, $isHidden): AdministeredUser {
            if ($this->users->emailIsTaken($email)) {
                throw UserAdministrationRefused::because(AdministrationRefusal::EmailAlreadyTaken);
            }

            $user = $this->users->create($name, $email, $hash, $role->id, $isHidden);

            // AUD-01. No password and no hash: AUD-03 makes the row permanent,
            // and a permanent copy of a credential outlives the account.
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::USER_CREATED),
                'user',
                $user->id,
                null,
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_id' => $user->roleId,
                    'role' => $user->roleSlug,
                    'is_active' => $user->isActive,
                ],
            );

            return $user;
        });
    }

    /** @throws UserAdministrationRefused */
    private function assertMayConfer(string $actorId, RoleSummary $role): void
    {
        $actor = $this->permissions->actorFor($actorId);

        // Read from the database on every call rather than taken from the
        // token: a role change must take effect on the next request, not on the
        // next login (§3.12 rule 5).
        $actorRole = $actor instanceof Actor ? $actor->role() : null;

        if (! RoleAssignmentPolicy::permitsSlug($actorRole, $role->slug)) {
            throw UserAdministrationRefused::because(AdministrationRefusal::RoleNotAssignable);
        }
    }
}
