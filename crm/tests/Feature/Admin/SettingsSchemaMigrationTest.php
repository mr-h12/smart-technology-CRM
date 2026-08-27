<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 2, Point 1.1 — `settings` and `system_limits`.
 *
 * **Two tables, not one, because §3.11 grants them to different people.**
 * `system settings` is Super Admin only; `system limits (SLAs, thresholds)` is
 * a separate row of the same table, also Super Admin only today — but they are
 * separate rows, separate §13 screens (4 and 6), and a matrix row may be
 * regranted at any time without a deployment (§3.12 rule 5). Splitting the
 * storage keeps the permission check at the table, where a repository can
 * enforce it once; a single table with a `group` column would make every read
 * carry a filter that one forgotten query could drop.
 *
 * **The value column is text, deliberately.** `DB-07` forbids float anywhere
 * near money, and a settings table that types its column `double precision` for
 * the convenience of one numeric limit would be a float in the schema whatever
 * the application later casts it to. Text plus a declared `value_type` keeps
 * the decision in the row, and BCMath reads it as a string.
 *
 * The constraints are the feature — most of what follows inserts something
 * invalid and requires the database to refuse it.
 */
final class SettingsSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['settings', 'system_limits'];

    /**
     * PostgreSQL SQLSTATEs. Asserted by code rather than by "something threw",
     * because a missing table throws too — 42P01 — and a test that accepts any
     * exception passes before the table it is describing has been written.
     */
    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    /** Mounted read-only by compose and by the CI test container. */
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    /**
     * @return list<array{string}>
     */
    public static function tables(): array
    {
        return array_map(static fn (string $t): array => [$t], self::TABLES);
    }

    // ───────────────────────────────────────────────────────── the block

    #[DataProvider('tables')]
    public function test_that_the_table_exists(string $table): void
    {
        self::assertTrue(Schema::hasTable($table), "Module 2 needs `{$table}`.");
    }

    /**
     * `DB-02` names the audit columns and `DB-01` requires the soft delete. Read
     * out of §4.8 rather than restated here, so a change to the rule fails this
     * test instead of quietly leaving these two tables behind.
     */
    #[DataProvider('tables')]
    public function test_that_the_table_carries_the_block_section_4_8_requires(string $table): void
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'the audit block below would only be agreeing with the migration that wrote it.',
        );

        $rule = null;
        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (str_contains($line, 'DB-02')) {
                $rule = $line;
                break;
            }
        }

        self::assertNotNull($rule, '§4.8 no longer states DB-02.');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn($table, $column), "`{$table}` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn($table, 'deleted_at'), "`{$table}` is missing the DB-01 soft delete.");
    }

    #[DataProvider('tables')]
    public function test_that_the_key_is_a_uuid_primary_key(string $table): void
    {
        self::assertSame('uuid', Schema::getColumnType($table, 'id'), "`{$table}`.id is not a UUID (D-61).");
    }

    /**
     * `DB-07`. A float column is a defect whatever the application casts it to,
     * and `system_limits` is the table most likely to attract one.
     */
    #[DataProvider('tables')]
    public function test_that_no_column_stores_a_float(string $table): void
    {
        self::assertTrue(Schema::hasTable($table), "`{$table}` does not exist, so this check proves nothing.");

        $floats = DB::select(
            'select column_name, data_type from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            [$table, 'double precision', 'real'],
        );

        self::assertSame([], $floats, "`{$table}` has a float column — DB-07 forbids it.");
    }

    // ────────────────────────────────────────────────────── the constraints

    #[DataProvider('tables')]
    public function test_that_a_duplicate_live_key_is_refused(string $table): void
    {
        $this->insertRow($table, 'company.name');

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusalCode($table, 'company.name'));
    }

    /**
     * `DB-01` soft-deletes and `D-34` archives, so a plain UNIQUE would let one
     * archived row reserve its key forever. The uniqueness is partial.
     */
    #[DataProvider('tables')]
    public function test_that_an_archived_key_may_be_taken_again(string $table): void
    {
        $this->insertRow($table, 'company.phone');
        DB::table($table)->where('key', 'company.phone')->update(['deleted_at' => now()]);

        $this->insertRow($table, 'company.phone');

        self::assertSame(2, DB::table($table)->where('key', 'company.phone')->count());
    }

    #[DataProvider('tables')]
    public function test_that_an_undeclared_value_type_is_refused(string $table): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusalCode($table, 'company.address', 'float'));
    }

    #[DataProvider('tables')]
    public function test_that_a_key_may_not_be_blank(string $table): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusalCode($table, ''));
    }

    // ─────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward_again(): void
    {
        // Named, not `--step 1`. A step is "whatever migrated last", so the
        // next migration anyone adds silently points this test at itself —
        // which is exactly what happened when 1.2 landed behind 1.1.
        self::assertSame(0, Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_27_000000_create_settings_and_system_limits.php',
        ]));

        foreach (self::TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "down() left `{$table}` behind (DEV-03).");
        }

        self::assertSame(0, Artisan::call('migrate'));

        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "`{$table}` did not come back.");
        }
    }

    /** The SQLSTATE PostgreSQL answers with, or a failure if it accepted the row. */
    private function refusalCode(string $table, string $key, string $valueType = 'string'): string
    {
        try {
            $this->insertRow($table, $key, $valueType);
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail("`{$table}` accepted a row it should have refused.");
    }

    private function insertRow(string $table, string $key, string $valueType = 'string'): void
    {
        DB::table($table)->insert([
            'id' => Uuid::uuid7()->toString(),
            'key' => $key,
            'value' => 'Smart Technology',
            'value_type' => $valueType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
