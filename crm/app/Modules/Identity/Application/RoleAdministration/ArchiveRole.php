<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefusal;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /api/v1/roles/{id}` — retiring a role an administrator added.
 *
 * ── It is an archive, and the verb is the only thing that says "delete" ────
 *
 * `DB-01` soft-deletes every business table and `CLAUDE.md` reads "deactivate
 * or archive rather than delete". No row is removed here: the role gets
 * `deleted_at`, and so does every `role_permissions` row it held. The `DELETE`
 * verb is `OpenAPI §7.1`'s conventional resource route and describes the
 * caller's intent, not the storage.
 *
 * ⚠️ **§3.11's row says "create / edit role · permissions" and does not mention
 * retiring one.** Archiving is therefore a capability the owner requested with
 * Point 4.2 rather than one transcribed from the matrix. It is guarded by the
 * same `admin.manage_roles` the other two carry — a row §3.11 gives to the
 * Super Admin alone — so it cannot widen who administers roles, which is the
 * same argument that made `D-78` defensible. Recorded in `CHECKLIST.md` as a
 * decision awaiting a `D-xx` number, not silently absorbed into the matrix.
 *
 * ── The two refusals ───────────────────────────────────────────────────────
 *
 * A **system role** cannot go: `RolePermissionSeeder` restores a trashed row on
 * its next run, so archiving one is a change that quietly undoes itself.
 *
 * A role **somebody holds** cannot go either. `users.role_id` is NOT NULL and
 * §3.1 gives every user exactly one role, so archiving an assigned role leaves
 * accounts pointing at a row no listing returns, and `AuthorizeAction` answers
 * *denied* for everything they try with nothing on screen to explain it. The
 * documented way to stand somebody down is `D-34`'s deactivation, one account
 * at a time and with its own audit row.
 */
final readonly class ArchiveRole
{
    public function __construct(
        private ConnectionInterface $connection,
        private RoleDirectoryInterface $roles,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws RoleAdministrationRefused */
    public function handle(string $roleId): void
    {
        $role = $this->roles->findRole($roleId);

        if (! $role instanceof RoleView) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleNotFound);
        }

        if ($role->isSystem) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::SystemRoleCannotBeDeleted);
        }

        // Counted inside the same transaction as the archive below would be
        // better still, but the count and the write are ordered here for a
        // readable refusal: a caller who is about to be refused should not have
        // their request opened as a transaction at all.
        if ($this->roles->countUsersWithRole($role->id) > 0) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleHasAssignedUsers);
        }

        $this->connection->transaction(function () use ($role): void {
            // Re-asked inside the transaction. Between the check above and this
            // line, a concurrent `POST /users` could have assigned the role —
            // and a role archived out from under a brand-new account is exactly
            // the state the refusal exists to prevent.
            if ($this->roles->countUsersWithRole($role->id) > 0) {
                throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleHasAssignedUsers);
            }

            $grants = $this->roles->archiveRole($role->id);

            // `AUD-01`'s delete, inside the transaction per `DB-11`. The row
            // keeps what the role *was* — `AUD-03` makes it permanent, and the
            // archived row itself is the only other copy.
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::ROLE_ARCHIVED),
                'role',
                $role->id,
                [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'name_ar' => $role->nameAr,
                    'description' => $role->description,
                    'permissions' => $role->triples(),
                ],
                ['archived' => true, 'grants_archived' => $grants],
            );
        });
    }
}
