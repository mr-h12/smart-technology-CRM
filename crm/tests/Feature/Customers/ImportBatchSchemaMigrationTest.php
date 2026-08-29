<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 3, Point 1.2 — `import_batches`.
 *
 * ── ⚠️ The columns are not documented anywhere ─────────────────────────────
 *
 * `import_batches` is named **once** in the whole corpus — `MVP_Build_Plan_EN.md`
 * lists it among Module 3's tables — and no section describes a single field of
 * it. `OpenAPI_Contract_EN.md` says nothing about import at all. So the table is
 * required by an authoritative source and its shape is not.
 *
 * The column set is therefore derived from what **is** documented, and recorded
 * in `CHECKLIST.md` as a decision awaiting a `D-xx` rather than presented as a
 * reading of the documentation:
 *
 *   * **who and when** come free from `DB-02` — §3.3 gives `import (Excel)` to
 *     the Manager alone, so `created_by` already answers "who ran this".
 *   * **the file's name**, because a batch nobody can identify is a row that
 *     answers no question anyone would ask of it.
 *   * **three counts.** `D-31` makes "saved but incomplete" a real outcome
 *     distinct from "saved", so a batch that cannot say how many of each it
 *     produced cannot describe its own result. A fourth count for outright
 *     failures is **not** stored: it is `row_count - imported_count`, and a
 *     derivable column is a column that can disagree with itself.
 *
 * Everything beyond that — a status, an error log, a link from each imported
 * customer back to its batch — is invention, and is not here. The last of those
 * is a real question put to the owner rather than answered quietly.
 *
 * ── The arithmetic is enforced by the database ─────────────────────────────
 *
 * A batch claiming five imported rows out of three is nonsense, and `D-31`
 * makes an incomplete row an **imported** row — it saves, flagged — so the
 * incomplete count can never exceed the imported one. Both are CHECKs rather
 * than conventions the writer is trusted to keep (`DB-04`).
 */
final class ImportBatchSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const MIGRATION = 'database/migrations/2026_08_29_010000_create_import_batches.php';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('import_batches'), 'Module 3 needs `import_batches`.');
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
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
            self::assertTrue(Schema::hasColumn('import_batches', $column), "`import_batches` is missing {$column}.");
        }

        self::assertTrue(
            Schema::hasColumn('import_batches', 'deleted_at'),
            '`import_batches` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`. Counts are integers; nothing here may be a float. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('import_batches'), 'The table does not exist, so this proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['import_batches', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`import_batches` has a float column — DB-07 forbids it.');
    }

    // ────────────────────────────────────────────────────── the file's name

    public function test_that_a_batch_without_a_file_name_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['original_filename' => null])),
        );
    }

    public function test_that_a_blank_file_name_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['original_filename' => '  '])),
        );
    }

    // ───────────────────────────────────────────────────────────── the counts

    public function test_that_a_new_batch_counts_nothing(): void
    {
        $row = DB::table('import_batches')->where('id', $this->insert([]))->first();

        self::assertNotNull($row);
        self::assertSame(0, $row->row_count);
        self::assertSame(0, $row->imported_count);
        self::assertSame(0, $row->incomplete_count);
    }

    public function test_that_a_negative_count_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['row_count' => -1])));
    }

    /** A batch cannot import more rows than the file had. */
    public function test_that_more_imported_than_read_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['row_count' => 3, 'imported_count' => 5])),
        );
    }

    /** `D-31`: an incomplete row **saved**, so it is one of the imported ones. */
    public function test_that_more_incomplete_than_imported_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert([
                'row_count' => 10,
                'imported_count' => 4,
                'incomplete_count' => 5,
            ])),
        );
    }

    /** The ordinary case the CHECKs must not refuse. */
    public function test_that_a_partly_incomplete_import_is_accepted(): void
    {
        $id = $this->insert(['row_count' => 200, 'imported_count' => 195, 'incomplete_count' => 20]);

        self::assertSame(195, DB::table('import_batches')->where('id', $id)->value('imported_count'));
    }

    // ──────────────────────────────────────────────────────────── DB-04 key

    public function test_that_an_unknown_importer_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    // ─────────────────────────────────────────────────────── the file itself

    /**
     * The uploaded file is **not** stored, and that is scope rather than an
     * oversight: keeping it would pull in `SEC-15`'s virus scan, `D-39`'s size
     * limit and `D-38`'s permission-checked download, all of which are the files
     * work in Module 5. A column holding a path would be a promise none of that
     * machinery is behind yet.
     */
    public function test_that_no_column_holds_the_uploaded_file(): void
    {
        foreach (['file_path', 'path', 'storage_path', 'file', 'contents'] as $column) {
            self::assertFalse(
                Schema::hasColumn('import_batches', $column),
                "`{$column}` stores the upload, which needs SEC-15 and D-39 — Module 5's work.",
            );
        }
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasTable('import_batches'), 'down() left the table behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('import_batches'), 'The table did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('import_batches')->insert(array_merge([
            'id' => $id,
            'original_filename' => 'customers.csv',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail('The database accepted a write it had to refuse.');
    }
}
