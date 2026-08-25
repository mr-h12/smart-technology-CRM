<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\RoleAdministration\GrantDiff;
use App\Modules\Identity\Domain\RoleAdministration\PermissionView;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use App\Modules\Identity\Domain\RoleAdministration\RoleView;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * {@see RoleDirectoryInterface} over `roles`, `permissions` and
 * `role_permissions`.
 *
 * ── Grants are soft-deleted and restored, never removed ────────────────────
 *
 * `role_permissions` carries the `DB-02` block and a **partial** unique index,
 * `role_permissions_pair_unique_alive` on `(role_id, permission_id) WHERE
 * deleted_at IS NULL` — read off the live schema, not assumed. So revoking is
 * `deleted_at = now()`, and re-granting restores that same row rather than
 * inserting a second one. Two consequences, both wanted: `created_at` keeps
 * saying when the grant was **first** made (`DB-02`), and the index stays
 * satisfiable no matter how often a checkbox is toggled.
 *
 * `created_by` / `updated_by` are left null, following
 * {@see \App\Support\Database\HasStandardColumns}: this project records who
 * acted in `audit_log`, not in a column on every table.
 */
final class EloquentRoleDirectory implements RoleDirectoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @param  list<string>|null  $limitToSlugs
     * @return ReferencePage<RoleView>
     */
    public function listRoles(ReferenceListCriteria $criteria, ?array $limitToSlugs = null): ReferencePage
    {
        $query = Role::query();

        // Before the count, so `total` describes the page the caller can
        // actually reach (`OpenAPI §6.1`). `whereIn` with an empty list matches
        // nothing, which is the wanted answer rather than an accident.
        if ($limitToSlugs !== null) {
            $query->whereIn('slug', $limitToSlugs);
        }

        $isSystem = $criteria->filterBool('is_system');

        if ($isSystem !== null) {
            $query->where('is_system', $isSystem);
        }

        // OpenAPI §6.1: "Pagination always happens after authorization scoping"
        // — the count is taken from the same builder the page comes from.
        $total = $query->count();

        $rows = $query
            ->with('permissions')
            ->orderBy($criteria->sortField, $criteria->sortDescending ? 'desc' : 'asc')
            // A deterministic tiebreak. Two roles sorted on a non-unique column
            // would otherwise page non-deterministically — PostgreSQL may
            // return equal sort keys in any order, so a row can appear on two
            // pages of one listing, or on neither.
            ->orderBy('roles.id')
            ->offset($criteria->offset())
            ->limit($criteria->perPage)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrateRole($row);
        }

        return new ReferencePage($items, $total, $criteria->page, $criteria->perPage);
    }

    /** @return ReferencePage<PermissionView> */
    public function listPermissions(ReferenceListCriteria $criteria): ReferencePage
    {
        $query = Permission::query();

        $resource = $criteria->filterString('resource');

        if ($resource !== null) {
            $query->where('resource', $resource);
        }

        $total = $query->count();

        $rows = $query
            ->orderBy($criteria->sortField, $criteria->sortDescending ? 'desc' : 'asc')
            // `resource` alone repeats across a dozen rows, so without the next
            // two the ordering inside a resource is whatever the planner felt
            // like. These make the listing read the way §3.3…§3.11 print.
            ->orderBy('permissions.action')
            ->orderBy('permissions.scope')
            ->orderBy('permissions.id')
            ->offset($criteria->offset())
            ->limit($criteria->perPage)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydratePermission($row);
        }

        return new ReferencePage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function findRole(string $roleId): ?RoleView
    {
        // A submitted id that is not a UUID would make PostgreSQL raise
        // `invalid input syntax for type uuid` — a 500 where the contract wants
        // a 404. Checked rather than caught: OpenAPI §5.1 does not let a
        // malformed identifier and a missing row answer differently.
        if (! Str::isUuid($roleId)) {
            return null;
        }

        $role = Role::query()->with('permissions')->whereKey($roleId)->first();

        return $role instanceof Role ? self::hydrateRole($role) : null;
    }

    /**
     * @param  list<string>  $permissionIds
     * @return list<PermissionView>
     */
    public function permissionsByIds(array $permissionIds): array
    {
        $valid = array_values(array_filter($permissionIds, static fn (string $id): bool => Str::isUuid($id)));

        if ($valid === []) {
            return [];
        }

        $items = [];

        foreach (Permission::query()->whereKey($valid)->get() as $row) {
            $items[] = self::hydratePermission($row);
        }

        return $items;
    }

    public function slugTaken(string $slug): bool
    {
        return Role::query()->where('slug', $slug)->exists();
    }

    public function nameTaken(string $name, ?string $exceptRoleId = null): bool
    {
        return self::labelTaken('name', $name, $exceptRoleId);
    }

    public function arabicNameTaken(string $nameAr, ?string $exceptRoleId = null): bool
    {
        return self::labelTaken('name_ar', $nameAr, $exceptRoleId);
    }

    public function countUsersWithRole(string $roleId): int
    {
        if (! Str::isUuid($roleId)) {
            return 0;
        }

        // The Eloquent model applies `SoftDeletes`, so this counts live rows —
        // which is the question the refusal asks. An archived account is not
        // somebody a role archive would strand.
        return User::query()->where('role_id', $roleId)->count();
    }

    public function createRole(string $slug, string $name, ?string $nameAr, ?string $description): RoleView
    {
        $role = new Role;

        $role->fill([
            'slug' => $slug,
            'name' => $name,
            'name_ar' => $nameAr,
            'description' => $description,
            // §3.12 rule 5's ninth role. Never true from here — see the
            // interface note.
            'is_system' => false,
        ]);

        $role->save();

        // Re-read through the same path every other caller uses, so a created
        // role and a listed one cannot differ in shape. `permissions` is empty
        // on a row that was just inserted, and loading it says so explicitly
        // rather than leaving the relation unresolved.
        $role->load('permissions');

        return self::hydrateRole($role);
    }

    /** @param  array{name?: string, name_ar?: string|null, description?: string|null}  $attributes */
    public function updateRole(string $roleId, array $attributes): RoleView
    {
        $role = Role::query()->whereKey($roleId)->firstOrFail();

        // `array_key_exists` rather than `isset`: a key present with null is a
        // request to clear the column, and `isset` cannot see it.
        foreach (['name', 'name_ar', 'description'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $role->setAttribute($column, $attributes[$column]);
            }
        }

        $role->save();
        $role->load('permissions');

        return self::hydrateRole($role);
    }

    public function archiveRole(string $roleId): int
    {
        // The grants first: a live `role_permissions` row pointing at an
        // archived role is a grant nothing can revoke through the API.
        $grants = $this->connection->table('role_permissions')
            ->where('role_id', $roleId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        // `DB-01`: soft delete. `Role` uses `SoftDeletes`, so this sets
        // `deleted_at` — `forceDelete()` is forbidden on business data and is
        // not reachable from any endpoint.
        Role::query()->whereKey($roleId)->delete();

        return $grants;
    }

    private static function labelTaken(string $column, string $value, ?string $exceptRoleId): bool
    {
        $query = Role::query()->where($column, $value);

        if ($exceptRoleId !== null && Str::isUuid($exceptRoleId)) {
            $query->whereKeyNot($exceptRoleId);
        }

        return $query->exists();
    }

    /** @param  list<string>  $permissionIds */
    public function syncGrants(string $roleId, array $permissionIds): GrantDiff
    {
        $desired = array_values(array_unique($permissionIds));

        // Triples rather than ids, keyed by permission id: the diff is reported
        // in §3.2's notation because that is what the audit row keeps, and
        // resolving it here means one query instead of one per changed grant.
        $triples = $this->triplesById();

        $current = $this->connection->table('role_permissions')
            ->where('role_id', $roleId)
            ->whereNull('deleted_at')
            ->pluck('permission_id')
            ->all();

        $currentIds = [];

        foreach ($current as $id) {
            if (is_string($id)) {
                $currentIds[] = $id;
            }
        }

        $toGrant = array_values(array_diff($desired, $currentIds));
        $toRevoke = array_values(array_diff($currentIds, $desired));

        if ($toRevoke !== []) {
            // `DB-01`: soft delete. A physical delete would also lose the
            // `created_at` that says when the grant was first made.
            $this->connection->table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $toRevoke)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }

        foreach ($toGrant as $permissionId) {
            $restored = $this->connection->table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null, 'updated_at' => now()]);

            if ($restored > 0) {
                continue;
            }

            $this->connection->table('role_permissions')->insert([
                'id' => (string) Str::uuid7(),   // D-61
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return new GrantDiff(
            granted: self::namesFor($toGrant, $triples),
            revoked: self::namesFor($toRevoke, $triples),
        );
    }

    /** @return array<string, string> permission id => triple */
    private function triplesById(): array
    {
        $map = [];

        foreach (Permission::query()->withTrashed()->get() as $row) {
            $map[(string) $row->id] = $row->triple();
        }

        return $map;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, string>  $triples
     * @return list<string>
     */
    private static function namesFor(array $ids, array $triples): array
    {
        $names = [];

        foreach ($ids as $id) {
            // An id with no triple means the permission row was hard-deleted
            // out from under a grant, which `DB-01` forbids. Recording the id
            // is worse than recording nothing readable, so it is named as what
            // it is rather than dropped silently from a permanent record.
            $names[] = $triples[$id] ?? 'unknown:'.$id;
        }

        sort($names);

        return $names;
    }

    private static function hydrateRole(Role $role): RoleView
    {
        $permissions = [];

        foreach ($role->permissions as $permission) {
            $permissions[] = self::hydratePermission($permission);
        }

        return new RoleView(
            id: (string) $role->id,
            slug: (string) $role->slug,
            name: (string) $role->name,
            nameAr: $role->name_ar,
            isSystem: (bool) $role->is_system,
            description: $role->description,
            permissions: $permissions,
        );
    }

    private static function hydratePermission(Permission $permission): PermissionView
    {
        return new PermissionView(
            id: (string) $permission->id,
            resource: (string) $permission->resource,
            action: (string) $permission->action,
            scope: (string) $permission->scope,
        );
    }
}
