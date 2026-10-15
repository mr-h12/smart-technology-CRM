<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `D-123` (F-50 · 4) — an existing database gains `customer.change_status`:
 * every role that holds `customer.edit` at a scope is granted
 * `customer.change_status` at that same scope, as `PermissionMatrix` seeds a
 * fresh one (§3.3's new row). Read from the live grants, so a matrix the owner
 * has already changed on the screen is followed, not overwritten. Audited as
 * the system actor, on `2026_09_23_100000`'s precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->scopesByRole('edit') as $roleId => $scopes) {
                $granted = [];

                foreach ($scopes as $scope) {
                    $permissionId = $this->permissionId($scope) ?? $this->createPermission($scope);
                    DB::table('permissions')->where('id', $permissionId)->update(['deleted_at' => null]);
                    $this->grant($roleId, $permissionId);
                    $granted[] = "customer.change_status.{$scope}";
                }

                $this->audit($roleId, ['granted' => $granted, 'revoked' => []]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permissionIds = DB::table('permissions')->where('resource', 'customer')->where('action', 'change_status')->pluck('id');

            foreach ($this->scopesByRole('change_status') as $roleId => $scopes) {
                DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)
                    ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);

                $this->audit($roleId, [
                    'granted' => [],
                    'revoked' => array_map(static fn (string $scope): string => "customer.change_status.{$scope}", $scopes),
                ]);
            }

            DB::table('permissions')->whereIn('id', $permissionIds)
                ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @return array<string, list<string>> role id ⇒ the live scopes it holds for `customer.{$action}` */
    private function scopesByRole(string $action): array
    {
        $rows = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.resource', 'customer')
            ->where('permissions.action', $action)
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->orderBy('role_permissions.role_id')
            ->get(['role_permissions.role_id', 'permissions.scope']);

        $scopes = [];

        foreach ($rows as $row) {
            if (is_string($row->role_id) && is_string($row->scope)) {
                $scopes[$row->role_id][] = $row->scope;
            }
        }

        return $scopes;
    }

    /** @param array{granted: list<string>, revoked: list<string>} $change */
    private function audit(string $roleId, array $change): void
    {
        app(AuditRecorderInterface::class)->record(
            AuditEvent::of('ROLE_PERMISSIONS_UPDATED'),
            'role',
            $roleId,
            null,
            $change,
        );
    }

    private function permissionId(string $scope): ?string
    {
        $id = DB::table('permissions')->where('resource', 'customer')->where('action', 'change_status')
            ->where('scope', $scope)->value('id');

        return is_string($id) ? $id : null;
    }

    private function createPermission(string $scope): string
    {
        $id = (string) Str::uuid7();

        DB::table('permissions')->insert([
            'id' => $id, 'resource' => 'customer', 'action' => 'change_status', 'scope' => $scope,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function grant(string $roleId, string $permissionId): void
    {
        $restored = DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)
            ->whereNotNull('deleted_at')->orderByDesc('updated_at')->limit(1)
            ->update(['deleted_at' => null, 'updated_at' => now()]);

        $alive = DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)
            ->whereNull('deleted_at')->exists();

        if ($restored === 0 && ! $alive) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid7(), 'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
