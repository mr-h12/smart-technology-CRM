<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * QS.1 — `D-155`: a quotation line names its own product. `quotation_items`
 * gains `catalog_item_id` (NOT NULL, a foreign key to `catalog_items`),
 * backfilled from each line's supplier line — a soft-deleted one too, since a
 * supplier-offer edit soft-deletes every line it replaces — and reversible
 * (`DEV-03`).
 */
final class QuotationLineCatalogItemMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_23_000000_add_catalog_item_id_to_quotation_items.php';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private string $quotationId;

    private string $offerId;

    protected function setUp(): void
    {
        parent::setUp();

        $customerId = Uuid::uuid4()->toString();
        $dealId = Uuid::uuid4()->toString();
        $currencyId = Uuid::uuid4()->toString();
        $supplierId = Uuid::uuid4()->toString();

        $this->quotationId = Uuid::uuid4()->toString();
        $this->offerId = Uuid::uuid4()->toString();

        DB::table('customers')->insert(['id' => $customerId, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('currencies')->insert([
            'id' => $currencyId, 'code' => 'EGP', 'rounding_unit' => '1', 'rounding_enabled' => true, 'is_base' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->insert([
            'id' => $dealId, 'code' => 'DL-2026-0001', 'customer_id' => $customerId, 'last_activity_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('suppliers')->insert(['id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotations')->insert([
            'id' => $this->offerId, 'code' => 'SQ-2026-0001', 'supplier_id' => $supplierId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('quotations')->insert([
            'id' => $this->quotationId, 'code' => 'QT-2026-0001', 'deal_id' => $dealId, 'customer_id' => $customerId,
            'currency_id' => $currencyId, 'default_margin' => '20', 'discount_percent' => '0', 'rounding_unit' => '1',
            'rounding_enabled' => true, 'subtotal' => '0', 'additional_total' => '0', 'discount_amount' => '0', 'tax_base' => '0',
            'net_amount' => '0', 'total_before_round' => '0', 'final_total' => '0', 'rounding_diff' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertTrue(Schema::hasColumn('quotation_items', 'catalog_item_id'), 'D-155: the line names its own product.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertFalse(Schema::hasColumn('quotation_items', 'catalog_item_id'), 'down() left `quotation_items.catalog_item_id` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));

        $column = DB::selectOne(
            'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            ['quotation_items', 'catalog_item_id'],
        );
        self::assertInstanceOf(stdClass::class, $column, 'up() did not bring the column back.');
        self::assertSame(['uuid', 'NO'], [$column->data_type, $column->is_nullable]);

        $keys = DB::select(
            'select conname from pg_constraint where conrelid = to_regclass(?) '
            ."and contype = 'f' and pg_get_constraintdef(oid) like ?",
            ['quotation_items', 'FOREIGN KEY (catalog_item_id) REFERENCES catalog_items(id)%'],
        );
        self::assertCount(1, $keys, 'DB-04: the product is a foreign key to `catalog_items`.');
    }

    /** The backfill reads the supplier line whether or not it is alive, and fills archived quotation lines too. */
    public function test_that_every_line_is_backfilled_from_its_supplier_line_even_a_soft_deleted_one(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));

        $live = $this->supplierLine('Switch 24-port', deleted: false);
        $dead = $this->supplierLine('Router 8-port', deleted: true);
        $other = $this->supplierLine('Access point', deleted: false);

        $onLive = $this->line($live['line'], 1);
        $onDead = $this->line($dead['line'], 2);
        $archived = $this->line($other['line'], 3, ['deleted_at' => now()]);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasColumn('quotation_items', 'catalog_item_id'), 'up() did not add `quotation_items.catalog_item_id`.');

        self::assertSame(
            [$onLive => $live['item'], $onDead => $dead['item'], $archived => $other['item']],
            DB::table('quotation_items')->orderBy('line_no')->pluck('catalog_item_id', 'id')->all(),
        );
    }

    /** `D-155`: a line without a product, or naming one the catalog does not hold, is refused by the database. */
    public function test_that_a_line_without_a_known_product_is_refused(): void
    {
        $supplierLine = $this->supplierLine('Switch 24-port', deleted: false)['line'];

        self::assertSame(self::NOT_NULL_VIOLATION, $this->refusedWith(
            fn () => $this->line($supplierLine, 1, ['catalog_item_id' => null]),
        ));
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(
            fn () => $this->line($supplierLine, 1, ['catalog_item_id' => Uuid::uuid4()->toString()]),
        ));
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @return array{item: string, line: string} a catalog product and a supplier line on it */
    private function supplierLine(string $name, bool $deleted): array
    {
        $item = Uuid::uuid4()->toString();
        $line = Uuid::uuid4()->toString();

        DB::table('catalog_items')->insert(['id' => $item, 'kind' => 'product', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $line, 'supplier_quotation_id' => $this->offerId, 'catalog_item_id' => $item,
            'unit_price' => '1000.000000', 'quantity' => '5.0000',
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['item' => $item, 'line' => $line];
    }

    /** @param array<string, mixed> $overrides */
    private function line(string $supplierLineId, int $lineNo, array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('quotation_items')->insert(array_merge([
            'id' => $id, 'quotation_id' => $this->quotationId, 'supplier_quotation_item_id' => $supplierLineId, 'line_no' => $lineNo,
            'unit_cost' => '1000.000000', 'unit_cost_currency' => 'EGP', 'unit_cost_fx_rate_at_time' => '1', 'unit_cost_base' => '1000.000000',
            'margin_percent' => '20', 'unit_price' => '1200.000000', 'quantity' => '1.0000', 'line_total' => '1200.000000', 'line_cost' => '1000.000000',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /** The SQLSTATE a write is refused with — inside a savepoint, so a second write in the same test still runs. */
    private function refusedWith(callable $write): string
    {
        try {
            DB::transaction(static function () use ($write): void {
                $write();
            });
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            self::assertIsString($state);

            return $state;
        }

        self::fail('The database accepted a row it should have refused.');
    }
}
