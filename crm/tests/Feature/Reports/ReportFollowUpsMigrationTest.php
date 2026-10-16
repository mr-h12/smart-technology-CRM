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
 * Module 13, slice 3 — a daily report's follow-ups and several notes (`D-131`,
 * spec §3): `report_follow_ups` (a customer and a note per row) and
 * `report_notes` (one body per note), both soft-deleted and audited (`DB-01`).
 * The migration copies each draft or returned report's non-blank
 * `reports.notes` as its first note; the old column stays, unread (`D-122`).
 */
final class ReportFollowUpsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_000000_add_follow_ups_and_notes_to_reports.php';

    private const AUDIT = ['created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_at'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_report_follow_ups_has_its_columns_keys_and_index(): void
    {
        self::assertTrue(Schema::hasTable('report_follow_ups'), 'D-131: report_follow_ups does not exist.');
        foreach (['id', 'report_id', 'customer_id', 'note', ...self::AUDIT] as $column) {
            self::assertTrue(Schema::hasColumn('report_follow_ups', $column), "report_follow_ups has no {$column}.");
        }

        self::assertSame('uuid', $this->dataType('report_follow_ups', 'id'));
        self::assertFalse($this->nullable('report_follow_ups', 'note'), 'A follow-up\'s note is required (D-131).');
        self::assertSame('reports', $this->foreignTable('report_follow_ups', 'report_id'));
        self::assertSame('customers', $this->foreignTable('report_follow_ups', 'customer_id'));
        self::assertTrue($this->hasIndex('report_follow_ups', 'report_id, created_at'), 'Spec §3: the list is shown by (report_id, created_at).');
    }

    public function test_that_report_notes_has_its_columns_keys_and_index(): void
    {
        self::assertTrue(Schema::hasTable('report_notes'), 'D-131: report_notes does not exist.');
        foreach (['id', 'report_id', 'body', ...self::AUDIT] as $column) {
            self::assertTrue(Schema::hasColumn('report_notes', $column), "report_notes has no {$column}.");
        }

        self::assertSame('uuid', $this->dataType('report_notes', 'id'));
        self::assertFalse($this->nullable('report_notes', 'body'), 'A note\'s body is required (D-131).');
        self::assertSame('reports', $this->foreignTable('report_notes', 'report_id'));
        self::assertTrue($this->hasIndex('report_notes', 'report_id, created_at'), 'Spec §3: the list is shown by (report_id, created_at).');
    }

    /** Spec §3: the not-blank CHECKs — the database refuses what the Form Request already refuses. */
    public function test_that_a_blank_note_or_body_is_refused(): void
    {
        $report = $this->report($this->user());
        $customer = $this->customer();

        foreach (['', '   '] as $blank) {
            $this->expectDatabaseError('23514', fn () => $this->followUp($report, $customer, $blank));
            $this->expectDatabaseError('23514', fn () => $this->note($report, $blank));
        }

        // A real text is allowed, so the refusals above are the CHECK and not a missing table.
        $this->followUp($report, $customer, 'Called; wants a visit.');
        $this->note($report, 'A good day.');
        self::assertSame(1, DB::table('report_follow_ups')->count());
        self::assertSame(1, DB::table('report_notes')->count());
    }

    /**
     * `D-131`: each draft or returned report's non-blank notes text becomes its
     * first note; a submitted report's is its frozen copy's and is not copied;
     * `reports.notes` is kept. The rows are written before the migration runs,
     * so the copy is the migration's own.
     */
    public function test_that_an_editable_reports_notes_become_its_first_note(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        $author = $this->user();
        $draft = $this->report($author, ['notes' => 'Draft words.']);
        $returned = $this->report($author, ['status' => 'returned', 'notes' => 'Returned words.']);
        $submitted = $this->report($author, ['status' => 'submitted', 'notes' => 'Frozen words.']);
        $blank = $this->report($author, ['notes' => '   ']);
        $empty = $this->report($author);

        self::assertSame(0, Artisan::call('migrate'));

        self::assertSame(['Draft words.'], $this->bodies($draft));
        self::assertSame(['Returned words.'], $this->bodies($returned));
        self::assertSame([], $this->bodies($submitted), 'A submitted report\'s notes live in its frozen copy (§11.3).');
        self::assertSame([], $this->bodies($blank), 'A blank notes text is no note.');
        self::assertSame([], $this->bodies($empty));

        self::assertSame('Draft words.', DB::table('reports')->where('id', $draft)->value('notes'), 'D-131: the old column is kept (the D-122 precedent).');
    }

    /**
     * DEV-03. This slice's migration, rolled back by its path —
     * inside RefreshDatabase's transaction, never on the dev database.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertTrue(Schema::hasTable('report_follow_ups'), 'report_follow_ups is missing before the rollback.');
        self::assertTrue(Schema::hasTable('report_notes'), 'report_notes is missing before the rollback.');
        $id = $this->report($this->user(), ['notes' => 'Kept in the old column.']);

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasTable('report_follow_ups'), 'down() left report_follow_ups behind (DEV-03).');
        self::assertFalse(Schema::hasTable('report_notes'), 'down() left report_notes behind (DEV-03).');
        self::assertTrue(Schema::hasColumn('reports', 'notes'), 'down() must not touch reports.notes.');
        self::assertSame('Kept in the old column.', DB::table('reports')->where('id', $id)->value('notes'));

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('report_follow_ups'), 'report_follow_ups did not come back.');
        self::assertTrue(Schema::hasTable('report_notes'), 'report_notes did not come back.');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function bodies(string $report): array
    {
        /** @var list<string> */
        return DB::table('report_notes')->where('report_id', $report)->whereNull('deleted_at')->pluck('body')->all();
    }

    private function dataType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );

        return is_object($row) && is_string($row->data_type ?? null) ? $row->data_type : null;
    }

    private function nullable(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );

        return is_object($row) && ($row->is_nullable ?? null) === 'YES';
    }

    private function hasIndex(string $table, string $columns): bool
    {
        return DB::table('pg_indexes')
            ->where('tablename', $table)
            ->where('indexdef', 'like', '%('.$columns.')%')
            ->exists();
    }

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
            'created_by' => $author, 'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function customer(): string
    {
        $id = (string) Str::uuid7();
        DB::table('customers')->insert([
            'id' => $id, 'name' => 'Nile Trading', 'customer_status' => 'prospect',
            'is_archived' => false, 'is_incomplete' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function followUp(string $report, string $customer, string $note): void
    {
        DB::table('report_follow_ups')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $report, 'customer_id' => $customer, 'note' => $note,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function note(string $report, string $body): void
    {
        DB::table('report_notes')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $report, 'body' => $body,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
