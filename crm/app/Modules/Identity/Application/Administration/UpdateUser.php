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
use App\Modules\Identity\Domain\Rbac\Actor;
use App\Modules\Identity\Domain\Rbac\Role;
use Illuminate\Database\ConnectionInterface;

/**
 * §9 Flow 9's "Later changes: role change · deactivation".
 *
 * ── Rule 7 applies to `PATCH`, not only to `POST` ──────────────────────────
 *
 * §3.12 rule 7 says the Manager "may not **create** Manager, CEO or Super Admin
 * accounts". Moving an existing account onto one of those roles produces the
 * same account by a different verb, so {@see RoleAssignmentPolicy} is asked
 * here too. A rule that guards only the creation endpoint is a rule with an
 * unguarded door beside it.
 *
 * ── Two audit rows for one role change, on purpose ─────────────────────────
 *
 * `USER_UPDATED` records the diff; `ROLE_CHANGED` exists because §3.12 rule 4
 * lists "role change" among the **mandatory** entries, and mandatory means
 * findable — an auditor filters on `event`, not on the contents of a JSON
 * column. Both are written inside the same transaction as the change itself
 * (`DB-11`, `AUD-01`).
 *
 * ── What cannot be changed here ────────────────────────────────────────────
 *
 * `password` (that is §9 Flow 0's own endpoint), `is_active` (the two
 * activation actions, so the switch always writes its own audit event), and
 * `is_hidden` (derived from the role, never submitted).
 */
final readonly class UpdateUser
{
    public function __construct(
        private ConnectionInterface $connection,
        private UserDirectoryInterface $users,
        private PermissionRepositoryInterface $permissions,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @param  array{name?: string, email?: string, role_id?: string}  $submitted
     *
     * @throws UserAdministrationRefused
     */
    public function handle(string $actorId, string $userId, array $submitted): AdministeredUser
    {
        $existing = $this->users->find($userId);

        if (! $existing instanceof AdministeredUser) {
            throw UserAdministrationRefused::because(AdministrationRefusal::UserNotFound);
        }

        $role = null;

        if (array_key_exists('role_id', $submitted) && $submitted['role_id'] !== $existing->roleId) {
            $role = $this->resolveAssignableRole($actorId, $submitted['role_id']);
        }

        return $this->connection->transaction(function () use ($existing, $submitted, $role): AdministeredUser {
            $changes = [];
            $before = [];
            $after = [];

            if (array_key_exists('name', $submitted) && $submitted['name'] !== $existing->name) {
                $changes['name'] = $submitted['name'];
                $before['name'] = $existing->name;
                $after['name'] = $submitted['name'];
            }

            if (array_key_exists('email', $submitted) && $submitted['email'] !== $existing->email) {
                if ($this->users->emailIsTaken($submitted['email'], $existing->id)) {
                    throw UserAdministrationRefused::because(AdministrationRefusal::EmailAlreadyTaken);
                }

                $changes['email'] = $submitted['email'];
                $before['email'] = $existing->email;
                $after['email'] = $submitted['email'];
            }

            if ($role instanceof RoleSummary) {
                $changes['role_id'] = $role->id;
                // §3.12 rule 6 follows the role. A promotion into a hidden role
                // must hide the account, and a move out of one must reveal it,
                // or the flag records what the user used to be.
                $changes['is_hidden'] = Role::tryFrom($role->slug)?->isHidden() === true;
                $before['role'] = $existing->roleSlug;
                $after['role'] = $role->slug;
            }

            if ($changes === []) {
                // Nothing submitted differs from what is stored. Returning the
                // resource unchanged is the honest answer: writing an audit row
                // saying nothing happened pollutes a permanent log (AUD-03),
                // and refusing would make a retried PATCH fail.
                return $existing;
            }

            $updated = $this->users->update($existing->id, $changes);

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::USER_UPDATED),
                'user',
                $updated->id,
                $before,
                $after,
            );

            if ($role instanceof RoleSummary) {
                $this->audit->record(
                    AuditEvent::of(IdentityAuditEvents::ROLE_CHANGED),
                    'user',
                    $updated->id,
                    ['role' => $existing->roleSlug, 'role_id' => $existing->roleId],
                    ['role' => $role->slug, 'role_id' => $role->id],
                );
            }

            return $updated;
        });
    }

    /** @throws UserAdministrationRefused */
    private function resolveAssignableRole(string $actorId, string $roleId): RoleSummary
    {
        $role = $this->users->findRole($roleId);

        if (! $role instanceof RoleSummary) {
            throw UserAdministrationRefused::because(AdministrationRefusal::RoleNotFound);
        }

        $actor = $this->permissions->actorFor($actorId);
        $actorRole = $actor instanceof Actor ? $actor->role() : null;

        if (! RoleAssignmentPolicy::permitsSlug($actorRole, $role->slug)) {
            throw UserAdministrationRefused::because(AdministrationRefusal::RoleNotAssignable);
        }

        return $role;
    }
}
