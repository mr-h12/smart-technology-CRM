<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-09 · 1.2 — `supplier_import_batches` and `suppliers.is_incomplete` (`D-85`).
 *
 * The batch table is `import_batches`' shape (Module 3, Point 1.2) owned by the
 * Suppliers module: modules do not read or write each other's tables, so the
 * customers' table is not shared. The arithmetic is the same and is the
 * database's to enforce (`DB-04`); `D-31` makes an incomplete row an imported
 * one, so it can never outnumber them.
 */
final class SupplierImportBatchSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_21_000000_add_supplier_import_columns_and_batches.php';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    public function test_that_the_table_carries_the_standard_block(): void
    {
        foreach (['id', 'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('supplier_import_batches', $column),
                "`supplier_import_batches` is missing {$column} (DB-01, DB-02).",
            );
        }
    }

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

    public function test_that_a_new_batch_counts_nothing(): void
    {
        $row = DB::table('supplier_import_batches')->where('id', $this->insert([]))->first();

        self::assertNotNull($row);
        self::assertSame(0, $row->row_count);
        self::assertSame(0, $row->imported_count);
        self::assertSame(0, $row->incomplete_count);
    }

    public function test_that_a_negative_count_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert(['incomplete_count' => -1])));
    }

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

    public function test_that_a_partly_incomplete_import_is_accepted(): void
    {
        $id = $this->insert(['row_count' => 200, 'imported_count' => 195, 'incomplete_count' => 20]);

        self::assertSame(195, DB::table('supplier_import_batches')->where('id', $id)->value('imported_count'));
    }

    public function test_that_an_unknown_importer_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['created_by' => Uuid::uuid4()->toString()])),
        );
    }

    /**
     * `DEV-03`, for this migration's own down(): it runs while `suppliers` and
     * everything after it still stand, so it must remove exactly its two
     * changes and nothing else — then up() puts them back.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        $migration = require base_path(self::MIGRATION);

        self::assertInstanceOf(Migration::class, $migration);
        self::assertTrue(method_exists($migration, 'down') && method_exists($migration, 'up'));

        $migration->down();

        self::assertFalse(Schema::hasTable('supplier_import_batches'), 'down() left the batch table behind.');
        self::assertFalse(Schema::hasColumn('suppliers', 'is_incomplete'), 'down() left `suppliers.is_incomplete`.');
        self::assertTrue(Schema::hasTable('suppliers'), 'down() removed more than it added.');

        $migration->up();

        self::assertTrue(Schema::hasTable('supplier_import_batches'), 'The batch table did not come back.');
        self::assertTrue(Schema::hasColumn('suppliers', 'is_incomplete'), 'The flag did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('supplier_import_batches')->insert(array_merge([
            'id' => $id,
            'original_filename' => 'suppliers.csv',
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
