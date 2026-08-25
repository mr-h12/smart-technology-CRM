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
     * `$limitToSlugs` narrows the listing to those slugs, and `null` means no
     * narrowing. It is applied **inside** the query rather than to the page
     * that comes back, because `OpenAPI §6.1` requires that "pagination always
     * happens after authorization scoping" — filtering afterwards would return
     * short pages and a `total` counting rows the caller may not see, which is
     * itself a disclosure.
     *
     * An empty list is not the same as null: it narrows to nothing, and the
     * honest answer for a caller entitled to see no roles at all is an empty
     * page rather than every page.
     *
     * @param  list<string>|null  $limitToSlugs
     * @return ReferencePage<RoleView>
     */
    public function listRoles(ReferenceListCriteria $criteria, ?array $limitToSlugs = null): ReferencePage;

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
     * Whether a **live** role already holds this slug.
     *
     * Asked before the insert rather than caught afterwards, because
     * `roles_slug_unique_alive` is a partial index and its violation arrives as
     * a driver exception with a PostgreSQL constraint name in it — a 500 where
     * `OpenAPI §5.1` wants a `422` naming the field the caller typed.
     *
     * An **archived** role does not reserve its slug (`DB-01`, and the index is
     * `WHERE deleted_at IS NULL`), so this asks only about live rows.
     */
    public function slugTaken(string $slug): bool;

    /** The same question about the English label, ignoring one role's own row. */
    public function nameTaken(string $name, ?string $exceptRoleId = null): bool;

    /** The same question about the Arabic label. */
    public function arabicNameTaken(string $nameAr, ?string $exceptRoleId = null): bool;

    /**
     * How many **live** users hold this role.
     *
     * `users.role_id` is NOT NULL, so a role cannot be archived out from under
     * an account without leaving it pointing at a row nothing returns. An
     * archived user (`DB-01`) is not counted — they are already stood down.
     */
    public function countUsersWithRole(string $roleId): int;

    /**
     * Insert a role an administrator added (§3.12 rule 5).
     *
     * `is_system` is false and is **not** a parameter: the flag marks §3.1's
     * eight, and the only writer that may set it is `RolePermissionSeeder`. An
     * endpoint that could set it would be an endpoint that could make a
     * custom role unarchivable by its own author.
     */
    public function createRole(string $slug, string $name, ?string $nameAr, ?string $description): RoleView;

    /**
     * Change a role's labels or description. The slug is never among them.
     *
     * A key that is absent is left alone; a key present with `null` clears the
     * column. That distinction is why this takes an array rather than four
     * nullable parameters — "not submitted" and "submitted as empty" are
     * different instructions and a nullable parameter cannot tell them apart.
     *
     * @param  array{name?: string, name_ar?: string|null, description?: string|null}  $attributes
     */
    public function updateRole(string $roleId, array $attributes): RoleView;

    /**
     * Archive a role and the grants it holds (`DB-01`).
     *
     * The grants go with it because a live `role_permissions` row pointing at
     * an archived role is a grant no screen shows and no listing can revoke —
     * and if the role is ever restored it would come back holding permissions
     * nobody reviewed.
     *
     * @return int how many grants were archived alongside it
     */
    public function archiveRole(string $roleId): int;

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
