<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Administration\RoleAssignmentPolicy;
use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefusal;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;

/**
 * `GET /api/v1/roles` — §3.1's roles and the `SEC-07` triples each one holds.
 *
 * ── Why the Super Admin row is listed here ─────────────────────────────────
 *
 * §3.12 rule 6 hides the Super Admin **user**, and
 * {@see \App\Modules\Identity\Domain\Contracts\UserDirectoryInterface} enforces
 * that on `users`. It says nothing about the *role*, and the role is already
 * printed in the master documentation: §3.1 names it and §3.11 gives it a whole
 * column. Hiding it here would mean the one endpoint that shows the permission
 * matrix shows a matrix the document does not contain, and an administrator
 * would have no way to see that a role exists whose grants they cannot edit.
 *
 * What is refused is *editing* it — {@see SyncRolePermissions}.
 *
 * ── Two callers, two listings ──────────────────────────────────────────────
 *
 * §3.11 gives "create / edit role · permissions" to the Super Admin alone, and
 * "create user" to the Super Admin **and the Manager**. Until Point 4.2 the
 * listing carried `admin.manage_roles`, so a Manager could create a user and
 * could not read a single role to pick a `role_id` from — a documented grant
 * that no client could exercise, recorded as an owner question since Point 3.2.
 *
 * The owner's answer, with Point 4.2: the route carries `admin.create_user`,
 * and a caller who does **not** also hold `admin.manage_roles` sees only the
 * roles {@see RoleAssignmentPolicy::assignableBy()} says they may confer. That
 * is not a smaller version of the matrix screen — it is the role picker, and it
 * is the only listing a Manager was ever entitled to. §3.12 rule 1 still
 * applies underneath: `CreateUser` and `UpdateUser` ask rule 7 again on the
 * `role_id` that comes back, so a Manager who guesses an id they were not shown
 * is refused by the use case, not by the shape of this list.
 *
 * ⚠️ **The narrowing is by permission, not by role name.** §3.12 rule 5 makes a
 * ninth role a configuration change, so an administrator may grant
 * `admin.manage_roles` to a role §3.1 never named — and asking "is the caller
 * the Super Admin" would refuse them the listing their own grants permit.
 */
final readonly class ListRoles
{
    public function __construct(
        private RoleDirectoryInterface $roles,
        private PermissionRepositoryInterface $actors,
        private AuthorizeAction $authorize,
    ) {}

    /** @return ReferencePage<RoleView> */
    public function handle(string $actorId, ReferenceListCriteria $criteria): ReferencePage
    {
        // Asked of the live matrix rather than of the caller's role name, for
        // the reason in the class note: §3.12 rule 5 lets an administrator
        // grant this to a role §3.1 never named.
        if ($this->authorize->decide($actorId, 'admin', 'manage_roles')->granted) {
            return $this->roles->listRoles($criteria);
        }

        $actor = $this->actors->actorFor($actorId);

        $slugs = [];

        foreach (RoleAssignmentPolicy::assignableBy($actor?->role()) as $role) {
            $slugs[] = $role->value;
        }

        // An empty list narrows to nothing. That is the honest page for a
        // caller who may confer no role at all — §3.11's *Others* column is `—`
        // on every row — and it is not reachable through the shipped routes,
        // because `permission:admin.create_user` has already refused them.
        return $this->roles->listRoles($criteria, $slugs);
    }

    /** @throws RoleAdministrationRefused */
    public function one(string $roleId): RoleView
    {
        $role = $this->roles->findRole($roleId);

        if (! $role instanceof RoleView) {
            throw RoleAdministrationRefused::because(RoleAdministrationRefusal::RoleNotFound);
        }

        return $role;
    }
}
