<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 13, first slice — the three tables the module card names:
 * `reports`, `report_versions` (§11.3's frozen copy) and `report_recipients`
 * (`D-127`, `D-129`).
 */
final class ReportsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_200000_create_reports.php';

    /** `D-135`: whether a report was delivered after it became Missed. */
    private const LATE_MIGRATION = 'database/migrations/2026_10_19_000000_add_submitted_late_to_reports.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_reports_has_its_columns_and_audit_columns(): void
    {
        foreach ([
            'id', 'code', 'type', 'status', 'author_id', 'period_start', 'period_end', 'notes',
            'version_token', 'submitted_at', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('reports', $column), "reports has no {$column}.");
        }

        self::assertSame('users', $this->foreignTable('reports', 'author_id'));
    }

    public function test_that_report_versions_and_recipients_have_their_keys(): void
    {
        foreach (['id', 'report_id', 'version', 'snapshot', 'created_by', 'created_at', 'deleted_at'] as $column) {
            self::assertTrue(Schema::hasColumn('report_versions', $column), "report_versions has no {$column}.");
        }
        foreach (['id', 'report_id', 'user_id', 'created_by', 'created_at', 'deleted_at'] as $column) {
            self::assertTrue(Schema::hasColumn('report_recipients', $column), "report_recipients has no {$column}.");
        }

        self::assertSame('reports', $this->foreignTable('report_versions', 'report_id'));
        self::assertSame('reports', $this->foreignTable('report_recipients', 'report_id'));
        self::assertSame('users', $this->foreignTable('report_recipients', 'user_id'));
    }

    /** The attachment pivot declared in Module 0 gets its parent key now that the parent exists. */
    public function test_that_report_files_now_points_at_reports(): void
    {
        self::assertSame('reports', $this->foreignTable('report_files', 'report_id'));
    }

    public function test_that_the_code_is_unique(): void
    {
        $author = $this->user();
        $this->report($author, ['code' => 'RPT-DY-2026-0001']);

        $this->expectDatabaseError('23505', fn () => $this->report($author, ['code' => 'RPT-DY-2026-0001']));
    }

    /** `D-125` builds six of §11.2's eight types; RPT-VS and RPT-PR wait for Modules 12 and 11. */
    public function test_that_a_type_outside_d_125s_six_is_refused(): void
    {
        $author = $this->user();

        foreach (['daily', 'weekly', 'monthly', 'deal', 'accounts', 'performance'] as $i => $type) {
            $this->report($author, ['type' => $type, 'code' => 'RPT-T-2026-000'.$i]);
        }

        $this->expectDatabaseError('23514', fn () => $this->report($author, ['type' => 'visits']));
    }

    /** §11.4's statuses plus `D-127`'s Missed; nothing else. */
    public function test_that_an_unknown_status_is_refused(): void
    {
        $this->expectDatabaseError('23514', fn () => $this->report($this->user(), ['status' => 'approved']));
    }

    public function test_that_a_period_ending_before_it_starts_is_refused(): void
    {
        $this->expectDatabaseError('23514', fn () => $this->report($this->user(), [
            'period_start' => '2026-10-15', 'period_end' => '2026-10-14',
        ]));
    }

    /** §11.3: each version is kept; a version number is never repeated for one report. */
    public function test_that_a_version_number_is_unique_per_report(): void
    {
        $report = $this->report($this->user());
        $this->version($report, 1);

        $this->expectDatabaseError('23505', fn () => $this->version($report, 1));
    }

    public function test_that_a_user_receives_one_report_once(): void
    {
        $report = $this->report($this->user());
        $recipient = $this->user();
        $this->recipient($report, $recipient);

        $this->expectDatabaseError('23505', fn () => $this->recipient($report, $recipient));
    }

    /** `D-135`: NOT NULL, false for every report already there; `down()` takes it away (DEV-03). */
    public function test_that_submitted_late_defaults_to_false_and_is_gone_after_down(): void
    {
        self::assertTrue(Schema::hasColumn('reports', 'submitted_late'), 'D-135: reports has no submitted_late.');

        $id = $this->report($this->user());
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('submitted_late'));

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::LATE_MIGRATION, '--force' => true]));
        self::assertFalse(Schema::hasColumn('reports', 'submitted_late'), 'DEV-03: down() left submitted_late behind.');

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
        self::assertTrue(Schema::hasColumn('reports', 'submitted_late'));

        // Last: a failed write aborts the test's transaction (SQLSTATE 25P02) for anything after it.
        $this->expectDatabaseError('23502', fn () => DB::table('reports')->where('id', $id)->update(['submitted_late' => null]));
    }

    // ── DEV-03
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::LATE_MIGRATION]));
        // Slice 4's `report_pdf_generations` and slice 3's tables reference `reports` (`D-132`, `D-131`): their migrations go first, as a rollback runs newest first.
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_18_000000_create_report_pdf_generations.php']));
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_17_000000_add_follow_ups_and_notes_to_reports.php']));
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        foreach (['reports', 'report_versions', 'report_recipients'] as $table) {
            self::assertFalse(Schema::hasTable($table), "down() left {$table} behind (DEV-03).");
        }
        self::assertTrue(Schema::hasTable('report_files'), 'down() must not drop the Module 0 pivot.');
        self::assertNull($this->foreignTable('report_files', 'report_id'), 'down() must drop the pivot key it added.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('reports'), 'reports did not come back.');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function foreignTable(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "select ccu.table_name from information_schema.table_constraints tc
             join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
             join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
             where tc.table_name = ? and tc.constraint_type = 'FOREIGN KEY' and kcu.column_name = ?",
            [$table, $column],
        );

        return is_object($row) && is_string($row->table_name ?? null) ? $row->table_name : null;
    }

    /** A missing table (42P01) must not pass for the constraint being tested. */
    private function expectDatabaseError(string $sqlState, callable $write): void
    {
        try {
            $write();
            self::fail("The write succeeded; SQLSTATE {$sqlState} was expected.");
        } catch (QueryException $e) {
            self::assertSame($sqlState, $e->getCode());
        }
    }

    private function user(): string
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $id, 'name' => 'Author '.$id, 'email' => $id.'@example.test', 'password' => 'x',
            'role_id' => DB::table('roles')->value('id'),
            'is_active' => true, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function report(string $author, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-DY-2026-'.substr($id, -4), 'type' => 'daily', 'status' => 'draft',
            'author_id' => $author, 'period_start' => '2026-10-15', 'period_end' => '2026-10-15',
            'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function version(string $report, int $version): void
    {
        DB::table('report_versions')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $report, 'version' => $version,
            'snapshot' => json_encode(['notes' => null]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function recipient(string $report, string $user): void
    {
        DB::table('report_recipients')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $report, 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
