<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 10 · 2.2a — `D-91` (owner, 2026-09-23): the Team Leader and
 * Procurement read every quotation, and their bare ✅ cells in §3.5 follow the
 * new `All` as §3.2 reads every bare ✅. `PermissionMatrix` already says so for
 * a fresh seed; `RolePermissionSeeder` only ever adds or restores a grant, so a
 * database seeded before `D-91` would keep the old `team` / `asgn` grants next
 * to `all`, and `rbac:verify` would call that drift. This swaps the live grants.
 *
 * - **A fresh database is untouched:** migrations run before any seed, so no
 *   role exists yet and the seeder writes `D-91`'s matrix itself.
 * - **Idempotent:** a role that no longer holds the grant being replaced is
 *   skipped, audit included.
 * - **Audited** as the admin screen audits a grant change
 *   (`ROLE_PERMISSIONS_UPDATED`, `SyncRolePermissions`), with its `granted` /
 *   `revoked` half — the full set is the screen's to state. No request, so the
 *   actor is the system (`AuditContext::system()`).
 * - **A permission row no live grant uses is retired**, so a migrated database
 *   and a freshly seeded one hold the same rows. `down()` restores it — or
 *   creates it, on a database seeded after `D-91`, which never had the row
 *   (`createPermission()`; six of the migration's tests fail without it).
 */
return new class extends Migration
{
    /** role slug => [its `quotation.*` actions moved to `all`, and the scope each held before `D-91`] */
    private const BEFORE = [
        'team_leader' => [['view', 'view_cost_and_margin', 'edit_margin', 'edit_tax', 'export_pdf'], 'team'],
        'procurement' => [['view', 'view_cost_and_margin'], 'asgn'],
    ];

    public function up(): void
    {
        foreach (self::BEFORE as $role => [$actions, $scope]) {
            $this->move($role, $actions, $scope, 'all');
        }
    }

    public function down(): void
    {
        foreach (self::BEFORE as $role => [$actions, $scope]) {
            $this->move($role, $actions, 'all', $scope);
        }
    }

    /** @param  list<string>  $actions */
    private function move(string $roleSlug, array $actions, string $from, string $to): void
    {
        DB::transaction(function () use ($roleSlug, $actions, $from, $to): void {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->whereNull('deleted_at')->value('id');

            if (! is_string($roleId)) {
                return;
            }

            $granted = [];
            $revoked = [];

            foreach ($actions as $action) {
                $fromId = $this->permissionId($action, $from);

                if ($fromId === null || ! $this->grantAlive($roleId, $fromId)) {
                    continue;
                }

                DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $fromId)
                    ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);

                $toId = $this->permissionId($action, $to) ?? $this->createPermission($action, $to);
                DB::table('permissions')->where('id', $toId)->update(['deleted_at' => null]);
                $this->grant($roleId, $toId);

                if (! DB::table('role_permissions')->where('permission_id', $fromId)->whereNull('deleted_at')->exists()) {
                    DB::table('permissions')->where('id', $fromId)->update(['deleted_at' => now(), 'updated_at' => now()]);
                }

                $granted[] = "quotation.{$action}.{$to}";
                $revoked[] = "quotation.{$action}.{$from}";
            }

            if ($granted !== []) {
                app(AuditRecorderInterface::class)->record(
                    AuditEvent::of('ROLE_PERMISSIONS_UPDATED'),
                    'role',
                    $roleId,
                    null,
                    ['granted' => $granted, 'revoked' => $revoked],
                );
            }
        });
    }

    /** `quotation.$action.$scope`, archived or not. */
    private function permissionId(string $action, string $scope): ?string
    {
        $id = DB::table('permissions')->where('resource', 'quotation')->where('action', $action)
            ->where('scope', $scope)->value('id');

        return is_string($id) ? $id : null;
    }

    private function createPermission(string $action, string $scope): string
    {
        $id = (string) Str::uuid7();

        DB::table('permissions')->insert([
            'id' => $id, 'resource' => 'quotation', 'action' => $action, 'scope' => $scope,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function grantAlive(string $roleId, string $permissionId): bool
    {
        return DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)
            ->whereNull('deleted_at')->exists();
    }

    /** `RolePermissionSeeder::seedGrants()`'s restore-or-insert. */
    private function grant(string $roleId, string $permissionId): void
    {
        $restored = DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)
            ->whereNotNull('deleted_at')->orderByDesc('updated_at')->limit(1)
            ->update(['deleted_at' => null, 'updated_at' => now()]);

        if ($restored === 0 && ! $this->grantAlive($roleId, $permissionId)) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid7(), 'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
