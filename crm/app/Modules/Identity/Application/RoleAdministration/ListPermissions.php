<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\RoleAdministration;

use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;

/**
 * `GET /api/v1/permissions` — every `resource.action.scope` row the matrix
 * screen can assign.
 *
 * ── Read from the table, never from `PermissionMatrix` ─────────────────────
 *
 * `SEC-07` and §3.12 rule 5 make the live matrix the database's. A listing
 * rebuilt from the seeded constant would show an administrator the rows the
 * code believes in rather than the rows that authorise anything — and the two
 * diverge the moment a later module seeds a permission or an administrator
 * retires one.
 *
 * ── ⚠️ What this does *not* return ─────────────────────────────────────────
 *
 * The two cells §3.12 rule 3 forbids — `customer.delete` and `catalog.delete` —
 * have no rows in `permissions` at all, because the seeder only writes a triple
 * for a cell somebody holds and those cells are granted to nobody. Their
 * absence here is therefore a *consequence*, not the enforcement:
 * {@see SyncRolePermissions} refuses them by name, so the rule still holds on
 * the day one of those rows is inserted by hand.
 */
final readonly class ListPermissions
{
    public function __construct(private RoleDirectoryInterface $roles) {}

    /** @return ReferencePage<PermissionView> */
    public function handle(ReferenceListCriteria $criteria): ReferencePage
    {
        return $this->roles->listPermissions($criteria);
    }
}
