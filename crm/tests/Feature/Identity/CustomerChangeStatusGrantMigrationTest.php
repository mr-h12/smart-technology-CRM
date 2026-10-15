<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `D-123` (F-50 · 4) — an existing database gains `customer.change_status`,
 * granted to every role that holds `customer.edit`, at the same scope, as a
 * fresh seed does (§3.3's new row). Audited as the system actor, as
 * `QuotationViewGrantMigrationTest`'s precedent is.
 */
final class CustomerChangeStatusGrantMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_200100_grant_customer_change_status.php';

    private const EXPECTED = [
        'indoor_sales' => 'own',
        'manager' => 'all',
        'outdoor_sales' => 'own',
        'outdoor_supervisor' => 'out',
        'team_leader' => 'team',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_rollback_revokes_and_the_migration_grants_it_audited(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertSame([], $this->changeStatusGrants());

        // `audit_log` is append-only (`AUD-03`): counted, not cleared.
        $before = DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->whereNull('user_id')->count();

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        self::assertSame(self::EXPECTED, $this->changeStatusGrants());
        self::assertSame($before + 5, DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->whereNull('user_id')->count());
    }

    public function test_a_migrated_database_holds_the_fresh_seeds_permission_rows(): void
    {
        $fresh = $this->livePermissionTriples();

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertNotSame($fresh, $this->livePermissionTriples());
        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        self::assertSame($fresh, $this->livePermissionTriples());
    }

    public function test_rbac_verify_reports_no_drift_after_the_migration(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        self::assertSame(0, Artisan::call('rbac:verify'));
        self::assertStringContainsString('matches §3', Artisan::output());
    }

    /** @return array<string, string> role slug ⇒ scope */
    private function changeStatusGrants(): array
    {
        /** @var array<string, string> $rows */
        $rows = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.resource', 'customer')
            ->where('permissions.action', 'change_status')
            ->where('roles.slug', '!=', 'super_admin')
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->pluck('permissions.scope', 'roles.slug')
            ->all();
        ksort($rows);

        return $rows;
    }

    /** @return list<string> */
    private function livePermissionTriples(): array
    {
        /** @var list<string> */
        return DB::table('permissions')->whereNull('deleted_at')
            ->selectRaw("resource || '.' || action || '.' || scope as triple")
            ->orderBy('triple')->pluck('triple')->all();
    }
}
