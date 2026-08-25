<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\RoleAdministration\GrantDiff;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;

/**
 * `roles`, `permissions` and `role_permissions` as §3.11's matrix screen needs
 * them.
 *
 * Separate from {@see PermissionRepositoryInterface}, which answers one
 * question on the authorisation path — "what scopes does this role hold on
 * `resource.action`" — and is memoised per request for `PRF-01`. This one reads
 * whole rows and writes grants; sharing an interface between a hot read and an
 * administrative write would make one of the two wrong.
 *
 * ── `SEC-07`: no implementation may answer from `PermissionMatrix` ─────────
 *
 * The same prohibition {@see PermissionRepositoryInterface} carries, for the
 * same reason. §3.12 rule 5 makes the live matrix the database's, so a listing
 * that re-derived itself from the seeded constant would show an administrator
 * the grants they *would* have had rather than the ones they have. The one
 * legitimate use of that class in this feature is the opposite direction —
 * {@see \App\Modules\Identity\Domain\Rbac\PermissionMatrix::forbiddenKeys()},
 * which is rule 3 and therefore not configuration at all.
 */
interface RoleDirectoryInterface
{
    /**
     * Live roles with the triples they grant.
     *
     * @return ReferencePage<RoleView>
     */
    public function listRoles(ReferenceListCriteria $criteria): ReferencePage;

    /** @return ReferencePage<PermissionView> */
    public function listPermissions(ReferenceListCriteria $criteria): ReferencePage;

    /** Live roles only — an archived row (`DB-01`) answers null. */
    public function findRole(string $roleId): ?RoleView;

    /**
     * The live permissions behind these ids, in no guaranteed order.
     *
     * Fewer results than ids means at least one did not resolve; the use case
     * turns that into `PermissionNotFound` rather than granting the subset that
     * happened to exist.
     *
     * @param  list<string>  $permissionIds
     * @return list<PermissionView>
     */
    public function permissionsByIds(array $permissionIds): array;

    /**
     * Make this role's live grants exactly this set, and report what moved.
     *
     * A desired-set write rather than add/remove calls, because the screen
     * submits a checkbox grid and two callers computing the delta differently
     * is how a grant survives being unchecked.
     *
     * `DB-01`: a revoked grant is a soft delete and a re-grant restores the
     * same row, so `role_permissions.created_at` keeps recording when the grant
     * was **first** made rather than when it was last toggled.
     *
     * @param  list<string>  $permissionIds
     */
    public function syncGrants(string $roleId, array $permissionIds): GrantDiff;
}
