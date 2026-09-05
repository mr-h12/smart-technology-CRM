<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Rbac\VerifyPermissionMatrix;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `rbac:verify` — does the live grant set still say what §3 says?
 *
 * ── Why a command and not a test ──────────────────────────────────────────
 *
 * `RbacMatrixDataTest` already compares the two, and it is green, and it was
 * green while the development database granted the Manager `admin.manage_roles`
 * for five days. It runs under `RefreshDatabase`, so it only ever sees a
 * database the seeder has just built: it proves the seeder agrees with the
 * matrix, and it can never see a running system. `SEC-07` puts the matrix **in
 * the database** and §3.12 rule 5 makes changing it a configuration change, so
 * the live set is *expected* to be editable — which is exactly why something has
 * to be able to look at a real database and say how far it has drifted.
 *
 * ── What "divergence" means here, and its ceiling ─────────────────────────
 *
 * Every documented role (`Role::cases()`) against every documented permission
 * (`PermissionMatrix::all()`). **Not covered:** a `resource.action` pair §3 does
 * not declare at all, and a role an administrator created after seeding. Both
 * are legitimate under `SEC-07` — a new permission is data, not a defect — and
 * reporting them as divergence would make the command cry wolf on its first
 * legitimate use. The ceiling is stated rather than silently chosen.
 */
final class VerifyPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────── aligned

    public function test_that_a_freshly_seeded_database_is_aligned(): void
    {
        $code = Artisan::call('rbac:verify');

        self::assertSame(0, $code);
        self::assertStringContainsString('matches §3', Artisan::output());
    }

    public function test_that_the_use_case_reports_no_divergence_when_seeded(): void
    {
        $divergence = $this->app->make(VerifyPermissionMatrix::class)->handle();

        self::assertSame([], $divergence->extra);
        self::assertSame([], $divergence->missing);
        self::assertTrue($divergence->isAligned());
    }

    // ───────────────────────────────────────────────────────────────── extra

    /** The exact drift found on 2026-08-31: the Manager granted the RBAC screen. */
    public function test_that_a_grant_section_3_does_not_declare_is_reported(): void
    {
        $this->grant('manager', 'admin', 'manage_roles', 'all');

        // Captured rather than `expectsOutputToContain()` chained twice: the
        // second expectation is matched against what the first did not
        // consume, so two substrings from **one** line can never both match.
        // Measured — the line was correct and the assertion was not.
        $code = Artisan::call('rbac:verify');
        $output = Artisan::output();

        self::assertSame(1, $code);
        self::assertStringContainsString('granted beyond §3', $output);
        self::assertStringContainsString('manager | admin.manage_roles.all', $output);
        self::assertStringNotContainsString('missing from live', $output);
    }

    public function test_that_an_undeclared_grant_lands_in_extra_not_in_missing(): void
    {
        $this->grant('manager', 'admin', 'manage_roles', 'all');

        $divergence = $this->app->make(VerifyPermissionMatrix::class)->handle();

        self::assertContains('manager | admin.manage_roles.all', $divergence->extra);
        self::assertSame([], $divergence->missing);
        self::assertFalse($divergence->isAligned());
    }

    // ─────────────────────────────────────────────────────────────── missing

    /** The other half of the same drift: the Manager had lost `customer.import`. */
    public function test_that_a_revoked_declared_grant_is_reported(): void
    {
        $this->revoke('manager', 'customer', 'import');

        $divergence = $this->app->make(VerifyPermissionMatrix::class)->handle();

        self::assertContains('manager | customer.import.all', $divergence->missing);
        self::assertSame([], $divergence->extra);
    }

    /** `DB-01`: a revoked grant is a soft delete, and a soft-deleted grant authorises nothing. */
    public function test_that_a_soft_deleted_grant_counts_as_missing(): void
    {
        DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->where('slug', 'manager')->pluck('id'))
            ->whereIn('permission_id', DB::table('permissions')
                ->where('resource', 'customer')->where('action', 'import')->pluck('id'))
            ->update(['deleted_at' => now()]);

        $divergence = $this->app->make(VerifyPermissionMatrix::class)->handle();

        self::assertContains('manager | customer.import.all', $divergence->missing);
    }

    // ─────────────────────────────────────────────────────── the exit code

    /**
     * The exit code is the alarm, on `EnsureAuditPartitionsCommand`'s reading of
     * `OBS-06`: a scheduler notices a non-zero exit and nothing else. A command
     * that printed the drift and returned 0 would report success for the single
     * state it exists to detect.
     */
    public function test_that_divergence_is_a_non_zero_exit(): void
    {
        $this->revoke('manager', 'customer', 'import');

        self::assertSame(1, Artisan::call('rbac:verify'));
    }

    // ─────────────────────────────────────────────────────────────── helpers

    private function grant(string $roleSlug, string $resource, string $action, string $scope): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        self::assertIsString($roleId);

        $permissionId = DB::table('permissions')
            ->where('resource', $resource)->where('action', $action)->where('scope', $scope)
            ->value('id');
        self::assertIsString($permissionId);

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid7(),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function revoke(string $roleSlug, string $resource, string $action): void
    {
        $deleted = DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->where('slug', $roleSlug)->pluck('id'))
            ->whereIn('permission_id', DB::table('permissions')
                ->where('resource', $resource)->where('action', $action)->pluck('id'))
            ->delete();

        self::assertGreaterThan(0, $deleted, "nothing to revoke for {$roleSlug} on {$resource}.{$action}");
    }
}
