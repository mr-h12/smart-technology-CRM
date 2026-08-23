<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `§3` carried into the database: eight roles, their `resource.action.scope`
 * permissions, and the grants between them.
 *
 * **This is not test data.** `SEC-07` puts the permission matrix *in the
 * database* — that is what makes a permission change an `INSERT` rather than a
 * deployment — so production needs these rows as much as development does, and
 * `seedsTestData()` returns false. `UserSeeder` is the opposite case.
 *
 * **Idempotent by construction, not by hope.** Every write is an
 * `updateOrCreate` on the natural key the schema already makes unique: a role's
 * slug, a permission's `(resource, action, scope)` triple, and a grant's
 * `(role_id, permission_id)` pair. Running it a second time updates the same
 * rows in place and creates nothing, which the seeder's own test asserts by
 * counting before and after and by comparing the identifiers.
 *
 * **Nothing is deleted.** A permission that disappears from `§3` is left where
 * it is rather than removed: `DB-01` forbids physical deletion of business
 * data, and revoking access is a decision with an audit trail, not a
 * side-effect of running a seeder. Divergence is reported by the test, not
 * silently repaired here.
 */
final class RolePermissionSeeder extends GuardedSeeder
{
    public function seedsTestData(): bool
    {
        // SEC-07: the matrix is configuration, and production runs on it.
        return false;
    }

    protected function seed(): void
    {
        // DB-11 and Coding Standards §7: this writes three tables, so it is one
        // transaction. A half-applied matrix is a permission set nobody
        // designed — some grants present, some missing, and no error to say so.
        DB::transaction(function (): void {
            $roles = $this->seedRoles();
            $permissions = $this->seedPermissions();

            $this->seedGrants($roles, $permissions);
        });
    }

    /**
     * `§3.1`'s eight, keyed by slug.
     *
     * `is_system` is true for all of them: these are the documented roles, and
     * Module 1's policies will refuse to delete or rename one. A ninth role
     * added by an administrator later gets `false` and is editable.
     *
     * @return array<string, string> slug => id
     */
    private function seedRoles(): array
    {
        $ids = [];

        foreach (RoleName::cases() as $role) {
            $row = Role::withTrashed()->updateOrCreate(
                ['slug' => $role->value],
                ['name' => $role->label(), 'is_system' => true],
            );

            // Restored explicitly rather than by putting deleted_at in the
            // update payload: it is not in $fillable, so Eloquent drops it
            // **silently** and the archived row stays archived while the
            // seeder reports success. Measured — the first version did exactly
            // that, and the restore test is what caught it.
            if ($row->trashed()) {
                $row->restore();
            }

            $ids[$role->value] = $row->id;
        }

        return $ids;
    }

    /**
     * Every distinct triple the matrix produces.
     *
     * `§3.2` makes the scope part of the permission's identity, so
     * `customer.view.own` and `customer.view.team` are two rows, not one row
     * with two scopes. Expanding `§3.3`…`§3.12` this way yields 143.
     *
     * @return array<string, string> triple => id
     */
    private function seedPermissions(): array
    {
        $ids = [];

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $grant) {
                $scope = $grant->scope()->value;
                $triple = $permission->resource().'.'.$permission->action().'.'.$scope;

                if (isset($ids[$triple])) {
                    continue;
                }

                $row = Permission::withTrashed()->updateOrCreate(
                    [
                        'resource' => $permission->resource(),
                        'action' => $permission->action(),
                        'scope' => $scope,
                    ],
                    [],
                );

                if ($row->trashed()) {
                    $row->restore();
                }

                $ids[$triple] = $row->id;
            }
        }

        return $ids;
    }

    /**
     * The 212 cells that are not "—".
     *
     * Written through the query builder rather than `attach()`, because
     * `attach()` inserts unconditionally and would duplicate on a second run —
     * the partial unique index would reject it, correctly, and the seeder would
     * fail rather than being idempotent. `role_permissions` also carries the
     * `DB-02` block, which a bare pivot insert would leave empty.
     *
     * @param  array<string, string>  $roles  slug => id
     * @param  array<string, string>  $permissions  triple => id
     */
    private function seedGrants(array $roles, array $permissions): void
    {
        $now = now();

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $roleSlug => $grant) {
                $triple = $permission->resource().'.'.$permission->action().'.'.$grant->scope()->value;

                $existing = DB::table('role_permissions')
                    ->where('role_id', $roles[$roleSlug])
                    ->where('permission_id', $permissions[$triple])
                    ->first();

                if ($existing !== null) {
                    // Update in place, and deliberately **not** created_at: a
                    // seeder that rewrites when a grant was first made destroys
                    // the only record of it, and DB-02 exists to keep that.
                    DB::table('role_permissions')
                        ->where('role_id', $roles[$roleSlug])
                        ->where('permission_id', $permissions[$triple])
                        ->update(['deleted_at' => null, 'updated_at' => $now]);

                    continue;
                }

                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid7(),
                    'role_id' => $roles[$roleSlug],
                    'permission_id' => $permissions[$triple],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
