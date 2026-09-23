<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

/**
 * Module 10 · 2.2a — `D-91`: the Team Leader and Procurement read every
 * quotation, overriding §3.5's `view` cells `Team` and `Asgn`; their bare ✅
 * cells in §3.5 follow the new `All` (§3.2's reading, owner, same day). The seeder only
 * adds grants, so a database seeded before `D-91` is corrected by a migration
 * that swaps the two live grants, audited as the admin screen audits a grant
 * change (`ROLE_PERMISSIONS_UPDATED`, `SEC-07`), and reversible (`DEV-03`).
 *
 * "Before `D-91`" is reached by rolling this one migration back, which is what
 * `down()` promises to restore.
 */
final class QuotationViewGrantMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_23_100000_grant_quotation_view_all_to_team_leader_and_procurement.php';

    private const MOVED_TL = ['edit_margin', 'edit_tax', 'export_pdf', 'view', 'view_cost_and_margin'];

    private const MOVED_PROCUREMENT = ['view', 'view_cost_and_margin'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_fresh_seed_grants_view_all_to_the_team_leader_and_procurement(): void
    {
        self::assertSame(['all'], $this->viewScopesOf('team_leader'));
        self::assertSame(['all'], $this->viewScopesOf('procurement'));
        self::assertSame(self::MOVED_TL, $this->quotationGrantsOf('team_leader', 'all'));
        self::assertSame(self::MOVED_PROCUREMENT, $this->quotationGrantsOf('procurement', 'all'));
    }

    /** Only these cells move; every explicit `Team` / `Asgn` cell stays. */
    public function test_the_team_leaders_explicit_cells_keep_team(): void
    {
        self::assertSame(
            ['approve', 'create', 'delete', 'edit', 'generate_pdf', 'record_customer_response', 'return_with_note', 'send_to_customer', 'submit_for_approval'],
            $this->quotationGrantsOf('team_leader', 'team'),
        );
        // §3.5 writes Procurement's two PDF cells as an explicit `Asgn`, not a bare ✅.
        self::assertSame(['export_pdf', 'generate_pdf'], $this->quotationGrantsOf('procurement', 'asgn'));
    }

    public function test_the_rollback_restores_the_old_grants(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertSame(['team'], $this->viewScopesOf('team_leader'));
        self::assertSame(['asgn'], $this->viewScopesOf('procurement'));
        self::assertSame([], $this->quotationGrantsOf('team_leader', 'all'));
        self::assertSame([], $this->quotationGrantsOf('procurement', 'all'));
        self::assertSame(self::triples(['view', 'view_cost_and_margin', 'edit_margin', 'edit_tax', 'export_pdf'], 'all'), $this->auditedOf('team_leader', 'revoked'));
    }

    public function test_the_migration_swaps_the_old_grants_and_audits_with_the_system_actor(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        // `audit_log` is append-only (`AUD-03`): counted, not cleared.
        $before = DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->whereNull('user_id')->count();

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        self::assertSame(['all'], $this->viewScopesOf('team_leader'));
        self::assertSame(['all'], $this->viewScopesOf('procurement'));

        self::assertSame(self::MOVED_TL, $this->quotationGrantsOf('team_leader', 'all'));
        self::assertSame(self::MOVED_PROCUREMENT, $this->quotationGrantsOf('procurement', 'all'));

        $tl = ['view', 'view_cost_and_margin', 'edit_margin', 'edit_tax', 'export_pdf'];
        self::assertSame(self::triples($tl, 'all'), $this->auditedOf('team_leader', 'granted'));
        self::assertSame(self::triples($tl, 'team'), $this->auditedOf('team_leader', 'revoked'));
        self::assertSame(self::triples(['view', 'view_cost_and_margin'], 'all'), $this->auditedOf('procurement', 'granted'));
        self::assertSame(self::triples(['view', 'view_cost_and_margin'], 'asgn'), $this->auditedOf('procurement', 'revoked'));
        self::assertSame($before + 2, DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->whereNull('user_id')->count());
    }

    /**
     * §3.12 rule 5: the live matrix is configuration. A grant an administrator
     * already withdrew is not handed back as `all`, and nothing is audited for
     * a role the migration did not change.
     */
    public function test_a_role_that_no_longer_holds_the_old_grants_is_left_alone(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        $procurement = DB::table('roles')->where('slug', 'procurement')->value('id');
        DB::table('role_permissions')->where('role_id', $procurement)
            ->whereIn('permission_id', DB::table('permissions')->where('resource', 'quotation')->where('scope', 'asgn')
                ->whereIn('action', ['view', 'view_cost_and_margin'])->pluck('id'))
            ->update(['deleted_at' => now()]);
        $audited = DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->where('entity_id', $procurement)->count();

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        self::assertSame([], $this->quotationGrantsOf('procurement', 'all'));
        self::assertSame($audited, DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')->where('entity_id', $procurement)->count());
        self::assertSame(self::MOVED_TL, $this->quotationGrantsOf('team_leader', 'all'));
    }

    /** `up()` retires the `team` / `asgn` rows; a later `down()` must bring them back, not just the grant. */
    public function test_a_rollback_after_the_migration_restores_the_retired_rows(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertSame(['team'], $this->viewScopesOf('team_leader'));
        self::assertSame(['asgn'], $this->viewScopesOf('procurement'));
    }

    /** A migrated database and a freshly seeded one hold the same permission rows. */
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

    /**
     * @param  list<string>  $actions
     * @return list<string>
     */
    private static function triples(array $actions, string $scope): array
    {
        return array_map(static fn (string $action): string => "quotation.{$action}.{$scope}", $actions);
    }

    /** @return list<string> the role's live `quotation.*` actions held at `$scope`, sorted */
    private function quotationGrantsOf(string $roleSlug, string $scope): array
    {
        /** @var list<string> */
        return DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.slug', $roleSlug)
            ->where('permissions.resource', 'quotation')
            ->where('permissions.scope', $scope)
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->orderBy('permissions.action')
            ->pluck('permissions.action')
            ->all();
    }

    /** @return list<string> every live `resource.action.scope`, sorted */
    private function livePermissionTriples(): array
    {
        /** @var list<string> */
        return DB::table('permissions')->whereNull('deleted_at')
            ->selectRaw("resource || '.' || action || '.' || scope as triple")
            ->orderBy('triple')->pluck('triple')->all();
    }

    /** @return list<string> the role's live `quotation.view` scopes */
    private function viewScopesOf(string $roleSlug): array
    {
        /** @var list<string> */
        return DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.slug', $roleSlug)
            ->where('permissions.resource', 'quotation')
            ->where('permissions.action', 'view')
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->orderBy('permissions.scope')
            ->pluck('permissions.scope')
            ->all();
    }

    /** @return list<string> the latest `ROLE_PERMISSIONS_UPDATED` row's `$key` for the role */
    private function auditedOf(string $roleSlug, string $key): array
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        $row = DB::table('audit_log')->where('event', 'ROLE_PERMISSIONS_UPDATED')
            ->where('entity_type', 'role')->where('entity_id', $roleId)
            ->orderByDesc('created_at')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($new);
        self::assertIsArray($new[$key] ?? null);

        /** @var list<string> */
        return $new[$key];
    }
}
