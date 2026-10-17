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
 * Module 13, slice 7 · 7.3 — `report_comments` (`D-127`: one flat list, no edit, no
 * remove, every comment audited; `DB-01`: soft delete and the audit columns). The author of
 * a comment is the row's `created_by`; the list is read by `(report_id, created_at)`.
 */
final class ReportCommentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT = ['created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_at'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_report_comments_has_its_columns_keys_and_index(): void
    {
        self::assertTrue(Schema::hasTable('report_comments'), 'D-127: report_comments does not exist.');
        foreach (['id', 'report_id', 'body', ...self::AUDIT] as $column) {
            self::assertTrue(Schema::hasColumn('report_comments', $column), "report_comments has no {$column}.");
        }

        self::assertSame('uuid', $this->scalar('select data_type from information_schema.columns where table_name = ? and column_name = ?', ['report_comments', 'id']));
        self::assertSame('NO', $this->scalar('select is_nullable from information_schema.columns where table_name = ? and column_name = ?', ['report_comments', 'body']), 'A comment\'s body is required.');
        self::assertSame('reports', $this->foreignTable('report_comments', 'report_id'));
        self::assertSame('users', $this->foreignTable('report_comments', 'created_by'));
        self::assertNotNull(
            DB::table('pg_indexes')->where('tablename', 'report_comments')->where('indexdef', 'like', '%(report_id, created_at)%')->first(),
            'The list is read by (report_id, created_at).',
        );
    }

    /** The database refuses what the Form Request already refuses; a real text goes in, so the refusal is the CHECK and not a missing table. */
    public function test_that_a_blank_body_is_refused(): void
    {
        $author = $this->user();
        $report = $this->report($author);

        foreach (['', '   '] as $blank) {
            try {
                DB::transaction(fn () => $this->comment($report, $author, $blank));
                self::fail('A blank comment was stored.');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
        }

        $this->comment($report, $author, 'A real comment.');
        self::assertSame(1, DB::table('report_comments')->count());
    }

    /** DEV-03: this slice's migration, found by the table it creates, rolled back by its path inside RefreshDatabase's transaction. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertTrue(Schema::hasTable('report_comments'), 'report_comments is missing before the rollback.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => $this->migration()]));
        self::assertFalse(Schema::hasTable('report_comments'), 'down() left report_comments behind (DEV-03).');
        self::assertTrue(Schema::hasTable('reports'), 'down() must not touch reports.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('report_comments'), 'report_comments did not come back.');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** The one migration file that creates `report_comments`, as a path relative to the project root. */
    private function migration(): string
    {
        $found = [];
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "'report_comments'")) {
                $found[] = 'database/migrations/'.basename($file);
            }
        }
        self::assertCount(1, $found, 'Exactly one migration should mention report_comments.');

        return $found[0];
    }

    /** @param array<int, string> $bindings */
    private function scalar(string $sql, array $bindings): ?string
    {
        $row = DB::selectOne($sql, $bindings);

        /** @var list<string|null> $values */
        $values = is_object($row) ? array_values((array) $row) : [];

        return is_object($row) ? (string) ($values[0] ?? '') : null;
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

    private function report(string $author): string
    {
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-DY-2026-'.substr($id, -4), 'type' => 'daily', 'status' => 'submitted',
            'author_id' => $author, 'period_start' => '2026-10-15', 'period_end' => '2026-10-15',
            'created_by' => $author, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function comment(string $report, string $by, string $body): void
    {
        DB::table('report_comments')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $report, 'body' => $body,
            'created_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
