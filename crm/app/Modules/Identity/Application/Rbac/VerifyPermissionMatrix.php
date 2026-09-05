<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Rbac;

use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Rbac\MatrixDivergence;
use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role;

/**
 * §3 as written, against §3 as the database currently holds it.
 *
 * ── Why this cannot be a test ─────────────────────────────────────────────
 *
 * `RbacMatrixDataTest` makes the same comparison and is green, and it was green
 * throughout the five days the development database granted the Manager
 * `admin.manage_roles`. It runs under `RefreshDatabase`: it can only ever see a
 * database the seeder has just written, so it proves the *seeder* agrees with
 * the matrix and says nothing about a running system. `RolePermissionSeeder`'s
 * own docblock promises that "divergence is reported by the test" — true of a
 * clean database, and the gap this class closes for every other one.
 *
 * ── Read-only, deliberately ───────────────────────────────────────────────
 *
 * Nothing here revokes anything. `DB-01` forbids physical deletion, `AUD-01`
 * requires a role change to be audited with an actor, and a maintenance command
 * has no actor to name. Repairing drift is Module 1's role-administration
 * endpoints, where the audit row is written; this only says what to repair.
 */
final readonly class VerifyPermissionMatrix
{
    public function __construct(private PermissionRepositoryInterface $permissions) {}

    public function handle(): MatrixDivergence
    {
        $roleIds = $this->permissions->roleIdsBySlug();

        $extra = [];
        $missing = [];

        foreach (PermissionMatrix::all() as $permission) {
            foreach (Role::cases() as $role) {
                $roleId = $roleIds[$role->value] ?? null;

                // A documented role absent from the database is a seeding
                // failure, not a grant difference, and RbacMatrixDataTest
                // already owns that case. Skipping keeps one command answering
                // one question.
                if ($roleId === null) {
                    continue;
                }

                $declared = $permission->grantFor($role)?->scope();
                $live = $this->permissions->scopesFor($roleId, $permission->resource(), $permission->action());

                $label = $role->value.' | '.$permission->resource().'.'.$permission->action().'.';

                foreach ($live as $scope) {
                    if ($scope !== $declared) {
                        $extra[] = $label.$scope->value;
                    }
                }

                if ($declared !== null && ! in_array($declared, $live, true)) {
                    $missing[] = $label.$declared->value;
                }
            }
        }

        sort($extra);
        sort($missing);

        return new MatrixDivergence($extra, $missing);
    }
}
