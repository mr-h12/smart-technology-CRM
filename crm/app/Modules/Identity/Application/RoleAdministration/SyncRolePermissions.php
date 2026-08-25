<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\GrantDiff;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefusal;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;
use Illuminate\Database\ConnectionInterface;

/**
 * `PATCH /api/v1/roles/{id}/permissions` — §3.12 rule 5's configuration change.
 *
 * "Permissions live in the database — changing this matrix is a configuration
 * change, not a deployment." This is the endpoint that sentence describes, and
 * the property it promises is **immediate effect**: the next request authorises
 * against the rows this one wrote, with nothing to restart and no cache to
 * clear. That holds because
 * {@see \App\Modules\Identity\Infrastructure\EloquentPermissionRepository}
 * memoises within a single request and nowhere longer — a cache that outlived a
 * request would turn rule 5 into a wait, and one that outlived a deploy would
 * turn it back into a deploy. The test asserts it by editing the matrix and
 * re-issuing the very request that was refused.
 *
 * ── The order of the four refusals ─────────────────────────────────────────
 *
 * Role found → role is editable → every id resolves → no id is forbidden.
 * Deliberate: a caller who submits a forbidden grant against a role that does
 * not exist is told the role does not exist, so a 422 can never become an
 * oracle for which role ids are real.
 *
 * ── Why an unchanged submission writes no audit row ────────────────────────
 *
 * `AUD-03` makes rows permanent. A screen that re-submits its checkbox grid on
 * every visit would otherwise fill the log with entries that record a click,
 * and an auditor asking "when did this role's permissions last change" would
 * get the wrong answer from a table that cannot be corrected.
 */
final readonly class SyncRolePermissions
{
    public function __construct(
        private ConnectionInterface $connection,
        private RoleDirectoryInterface $roles,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @param  list<string>  $permissionIds  the desired grant set, as submitted
     * @return array{role: RoleView, diff: GrantDiff}
     *
     * @throws RoleAdministrationRefused
     */
    public function handle(string $roleId, array $permissionIds): array
    {
        $role = $this->roles->findRole($roleId);

        if (! $role instanceof RoleView) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleNotFound);
        }

        // §3.1 answers for the Super Admin before any grant row is read, so
        // editing this role's rows would report success and change nothing.
        // See RoleAdministrationRefusal::RoleIsImmutable.
        if ($role->hasUnconditionalAccess()) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleIsImmutable);
        }

        $requested = array_values(array_unique($permissionIds));
        $resolved = $this->roles->permissionsByIds($requested);

        if (count($resolved) !== count($requested)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::PermissionNotFound);
        }

        self::assertNoneForbidden($resolved);

        $before = $role->triples();

        return $this->connection->transaction(function () use ($role, $requested, $before): array {
            $diff = $this->roles->syncGrants($role->id, $requested);

            $after = $this->roles->findRole($role->id);

            // findRole cannot answer null here — the row was located above and
            // this runs inside the same transaction — but Domain has no way to
            // say so, and asserting it with a fallback is cheaper than a
            // nullable return every caller would have to unwrap.
            $refreshed = $after instanceof RoleView ? $after : $role;

            if (! $diff->isEmpty()) {
                // `AUD-01`'s update, on the entity §3.11 calls "role ·
                // permissions". Inside the transaction, per `DB-11`: a grant
                // change that committed without its record is a change nobody
                // can attribute, and `AUD-03` means the record cannot be added
                // afterwards.
                //
                // §3.12 rule 4's "role change" is a *user's* role moving and is
                // written as ROLE_CHANGED by UpdateUser. This is the other
                // side of the same subject and gets its own name, so an auditor
                // filtering on the event column can ask the two questions
                // separately.
                $this->audit->record(
                    AuditEvent::of(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED),
                    'role',
                    $role->id,
                    ['permissions' => $before],
                    [
                        'permissions' => $refreshed->triples(),
                        'granted' => $diff->granted,
                        'revoked' => $diff->revoked,
                    ],
                );
            }

            return ['role' => $refreshed, 'diff' => $diff];
        });
    }

    /**
     * §3.12 rule 3 — the merged "❌ Forbidden for every role" cells.
     *
     * Checked by `resource.action` rather than by triple, because the rule
     * forbids the *action*: `customer.delete.own` is no more grantable than
     * `customer.delete.all`.
     *
     * @param  list<PermissionView>  $permissions
     *
     * @throws RoleAdministrationRefused
     */
    private static function assertNoneForbidden(array $permissions): void
    {
        foreach ($permissions as $permission) {
            // The same question `RolePayload` asks to lock the checkbox, so the
            // screen and the refusal cannot disagree (Point 5.3).
            if (! $permission->isGrantable()) {
                throw RoleAdministrationRefused::because(RoleAdministrationRefusal::GrantForbidden);
            }
        }
    }
}
