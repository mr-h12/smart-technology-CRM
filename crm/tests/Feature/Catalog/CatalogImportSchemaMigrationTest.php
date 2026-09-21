<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-10 · 1.3 — `catalog_items.is_incomplete`, `catalog_item_suppliers` and
 * `catalog_import_batches` (`D-86`).
 *
 * The link carries no price (§7.3: the catalog is descriptive only) and is
 * many-to-many, one live row per pair. The batch table is F-09's
 * `supplier_import_batches` shape, owned by Catalog: modules do not share tables.
 */
final class CatalogImportSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_22_000000_add_catalog_import_columns_and_links.php';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private const STANDARD_BLOCK = ['id', 'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_at'];

    // ─────────────────────────────── catalog_items.is_incomplete

    public function test_that_a_new_item_is_not_incomplete(): void
    {
        self::assertFalse(DB::table('catalog_items')->where('id', $this->item())->value('is_incomplete'));
    }

    // ─────────────────────────────── catalog_item_suppliers

    public function test_that_the_link_carries_the_standard_block(): void
    {
        foreach (self::STANDARD_BLOCK as $column) {
            self::assertTrue(Schema::hasColumn('catalog_item_suppliers', $column), "`catalog_item_suppliers` is missing {$column} (DB-01, DB-02).");
        }
    }

    /** §7.3: descriptive only. A price or a quantity on the link would be the supplier quotation's job done twice. */
    public function test_that_the_link_carries_no_price_or_quantity(): void
    {
        self::assertEqualsCanonicalizing(
            [...self::STANDARD_BLOCK, 'catalog_item_id', 'supplier_id'],
            Schema::getColumnListing('catalog_item_suppliers'),
        );
    }

    public function test_that_the_pair_refuses_a_second_live_link(): void
    {
        [$item, $supplier] = [$this->item(), $this->supplier()];
        $this->link($item, $supplier);

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusedWith(fn () => $this->link($item, $supplier)));
    }

    /** `DB-01`: an unlinked pair is a soft-deleted row, and linking it again is a new one. */
    public function test_that_a_pair_can_be_linked_again_after_it_was_unlinked(): void
    {
        [$item, $supplier] = [$this->item(), $this->supplier()];
        DB::table('catalog_item_suppliers')->where('id', $this->link($item, $supplier))->update(['deleted_at' => now()]);

        $this->link($item, $supplier);

        self::assertSame(2, DB::table('catalog_item_suppliers')->where('catalog_item_id', $item)->count());
    }

    public function test_that_one_item_may_carry_several_suppliers(): void
    {
        $item = $this->item();
        $this->link($item, $this->supplier());
        $this->link($item, $this->supplier());

        self::assertSame(2, DB::table('catalog_item_suppliers')->where('catalog_item_id', $item)->count());
    }

    public function test_that_a_link_to_an_unknown_item_is_refused(): void
    {
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->link(Uuid::uuid4()->toString(), $this->supplier())));
    }

    public function test_that_a_link_to_an_unknown_supplier_is_refused(): void
    {
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->link($this->item(), Uuid::uuid4()->toString())));
    }

    // ─────────────────────────────── catalog_import_batches

    public function test_that_the_batch_carries_the_standard_block(): void
    {
        foreach (self::STANDARD_BLOCK as $column) {
            self::assertTrue(Schema::hasColumn('catalog_import_batches', $column), "`catalog_import_batches` is missing {$column} (DB-01, DB-02).");
        }
    }

    public function test_that_a_batch_without_a_file_name_is_refused(): void
    {
        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(fn () => $this->batch(['original_filename' => null])));
    }

    public function test_that_a_blank_file_name_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->batch(['original_filename' => '  '])));
    }

    public function test_that_a_negative_count_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->batch(['incomplete_count' => -1])));
    }

    public function test_that_more_imported_than_read_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->batch(['row_count' => 3, 'imported_count' => 5])));
    }

    /** `D-31`: an incomplete row is **saved**, so it is one of the imported ones. */
    public function test_that_more_incomplete_than_imported_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->batch(['row_count' => 10, 'imported_count' => 4, 'incomplete_count' => 5])),
        );
    }

    public function test_that_a_partly_incomplete_import_is_accepted(): void
    {
        $id = $this->batch(['row_count' => 200, 'imported_count' => 195, 'incomplete_count' => 20]);

        self::assertSame(195, DB::table('catalog_import_batches')->where('id', $id)->value('imported_count'));
    }

    public function test_that_an_unknown_importer_is_refused(): void
    {
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->batch(['created_by' => Uuid::uuid4()->toString()])));
    }

    // ─────────────────────────────── DEV-03

    /** It removes exactly its three changes and nothing else — then up() puts them back. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        $migration = require base_path(self::MIGRATION);

        self::assertInstanceOf(Migration::class, $migration);
        self::assertTrue(method_exists($migration, 'down') && method_exists($migration, 'up'));

        $migration->down();

        self::assertFalse(Schema::hasTable('catalog_item_suppliers'), 'down() left the link table behind.');
        self::assertFalse(Schema::hasTable('catalog_import_batches'), 'down() left the batch table behind.');
        self::assertFalse(Schema::hasColumn('catalog_items', 'is_incomplete'), 'down() left `catalog_items.is_incomplete`.');
        self::assertTrue(Schema::hasTable('catalog_items') && Schema::hasTable('suppliers'), 'down() removed more than it added.');

        $migration->up();

        self::assertTrue(Schema::hasTable('catalog_item_suppliers'), 'The link table did not come back.');
        self::assertTrue(Schema::hasTable('catalog_import_batches'), 'The batch table did not come back.');
        self::assertTrue(Schema::hasColumn('catalog_items', 'is_incomplete'), 'The flag did not come back.');
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function item(): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('catalog_items')->insert(['id' => $id, 'kind' => 'product', 'name' => 'Pump', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function supplier(): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('suppliers')->insert(['id' => $id, 'name' => 'Supplier '.$id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function link(string $item, string $supplier): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('catalog_item_suppliers')->insert([
            'id' => $id,
            'catalog_item_id' => $item,
            'supplier_id' => $supplier,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function batch(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('catalog_import_batches')->insert(array_merge([
            'id' => $id,
            'original_filename' => 'catalog.csv',
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
