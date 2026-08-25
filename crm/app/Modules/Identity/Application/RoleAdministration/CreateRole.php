<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefusal;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;
use Illuminate\Database\ConnectionInterface;

/**
 * `POST /api/v1/roles` — §13 screen 3's "create new roles", and the half of
 * §3.11's "create / edit role · permissions" that Point 4.1 did not build.
 *
 * ── Why a ninth role is legitimate at all ──────────────────────────────────
 *
 * §3.12 rule 5: "Permissions live in the database — changing this matrix is a
 * configuration change, not a deployment." `Domain\Rbac\Role` says the same
 * thing from the other side: its eight cases are "the roles the system ships
 * with, not the roles it is limited to". So a role whose slug matches no enum
 * case is expected, and every place that reads one already handles it —
 * `RoleView::name()` answers null, and `RoleAssignmentPolicy::permitsSlug()`
 * lets only the Super Admin confer it.
 *
 * ── The order of the refusals ──────────────────────────────────────────────
 *
 * Slug, then English label, then Arabic label, then the grants. Each names the
 * field the caller typed, so a form can put the message next to the input that
 * caused it — and the three uniqueness checks run before anything is written,
 * because a partial-unique violation surfaces as a driver exception carrying a
 * PostgreSQL index name, which is a 500 where `OpenAPI §5.1` wants a 422.
 *
 * ── Grants go through the same rules as an edit ────────────────────────────
 *
 * The initial grant set is applied with {@see SyncRolePermissions}' own
 * directory call and validated against §3.12 rule 3 the same way. A create
 * endpoint that could grant a forbidden permission would be a way around the
 * rule that the edit endpoint enforces, which is how rule 3 stops being a rule.
 */
final readonly class CreateRole
{
    public function __construct(
        private ConnectionInterface $connection,
        private RoleDirectoryInterface $roles,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @param  list<string>  $permissionIds  the grants the role is born holding; may be empty
     *
     * @throws RoleAdministrationRefused
     */
    public function handle(
        string $slug,
        string $name,
        ?string $nameAr,
        ?string $description,
        array $permissionIds,
    ): RoleView {
        if ($this->roles->slugTaken($slug)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::SlugAlreadyTaken);
        }

        if ($this->roles->nameTaken($name)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::NameAlreadyTaken);
        }

        if ($nameAr !== null && $this->roles->arabicNameTaken($nameAr)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::ArabicNameAlreadyTaken);
        }

        $requested = array_values(array_unique($permissionIds));
        $resolved = $this->roles->permissionsByIds($requested);

        if (count($resolved) !== count($requested)) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::PermissionNotFound);
        }

        self::assertNoneForbidden($resolved);

        // `DB-11` and Coding Standards §7: the role, its grants and the audit
        // row are one write. A role that committed without its grants is a role
        // an administrator believes can do things it cannot, and `AUD-03` means
        // the record cannot be added afterwards.
        return $this->connection->transaction(function () use ($slug, $name, $nameAr, $description, $requested): RoleView {
            $role = $this->roles->createRole($slug, $name, $nameAr, $description);

            if ($requested !== []) {
                $this->roles->syncGrants($role->id, $requested);

                $reloaded = $this->roles->findRole($role->id);

                // findRole cannot answer null here — the row was inserted in
                // this transaction — but Domain has no way to say so, and a
                // fallback is cheaper than a nullable return every caller would
                // have to unwrap.
                $role = $reloaded instanceof RoleView ? $reloaded : $role;
            }

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::ROLE_CREATED),
                'role',
                $role->id,
                null,
                [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'name_ar' => $role->nameAr,
                    'description' => $role->description,
                    'is_system' => false,
                    // Triples, never ids — see IdentityAuditEvents::ROLE_CREATED.
                    'permissions' => $role->triples(),
                ],
            );

            return $role;
        });
    }

    /**
     * §3.12 rule 3 — the merged "❌ Forbidden for every role" cells.
     *
     * The same check {@see SyncRolePermissions::assertNoneForbidden()} makes,
     * duplicated deliberately rather than shared: making it a static helper on
     * one of the two use cases would make the other depend on it for a reason
     * that has nothing to do with what it does. Both are pinned by their own
     * test, and `RolePermissionManagementTest` asserts the two answer alike.
     *
     * @param  list<PermissionView>  $permissions
     *
     * @throws RoleAdministrationRefused
     */
    private static function assertNoneForbidden(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $permission->isGrantable()) {
                throw RoleAdministrationRefused::because(RoleAdministrationRefusal::GrantForbidden);
            }
        }
    }
}
