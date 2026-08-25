<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

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
 */
final readonly class ListRoles
{
    public function __construct(private RoleDirectoryInterface $roles) {}

    /** @return ReferencePage<RoleView> */
    public function handle(ReferenceListCriteria $criteria): ReferencePage
    {
        return $this->roles->listRoles($criteria);
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
